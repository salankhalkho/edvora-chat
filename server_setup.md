# edvora.chat — Complete Server Provisioning & Setup Guide

This document is a comprehensive step-by-step guide to provision and configure a fresh Linux VPS (Ubuntu 24.04 LTS) from scratch for **edvora.chat**, or to replicate this exact environment during a server migration.

---

## 📋 System Requirements & Technical Stack

| Component | Target Version / Service | Purpose |
|-----------|------------------------|---------|
| **OS** | Ubuntu 24.04 LTS (Noble) | Host Operating System |
| **Web Server** | Apache 2.4.58 (`mod_proxy_fcgi`) | HTTP/HTTPS Server |
| **PHP Engine** | PHP 8.2-FPM (`unix:/run/php/php8.2-fpm.sock`) | FastCGI Process Manager |
| **Database** | MariaDB 10.11+ | Primary Relational Database |
| **Cache & Queue** | Redis 7+ (`127.0.0.1:6379`) | Session Cache & Job Queue |
| **DNS Server** | BIND 9 (`named.service`) | Authoritative DNS for `ns1`/`ns2` |
| **Process Manager** | Supervisor (`supervisord`) | Background Worker Manager |
| **SSL Manager** | Certbot (`python3-certbot-apache`) | Let's Encrypt TLS Certificates |
| **PDF Processing** | Poppler Utils (`pdftotext`) | Knowledge Document Text Extraction |

---

## 🛠️ Step-by-Step Server Setup

### Step 1: System Update & Package Installation

Run as `root` or `sudo`:

```bash
# Update package indices
sudo apt-get update && sudo apt-get upgrade -y

# Install Core Utilities
sudo apt-get install -y curl git unzip zip poppler-utils software-properties-common

# Install Web Server (Apache 2.4) and modules
sudo apt-get install -y apache2
sudo a2enmod proxy proxy_fcgi setenvif rewrite ssl

# Install PHP 8.2 and Required Extensions
sudo apt-get install -y php8.2-cli php8.2-fpm php8.2-mysql php8.2-redis \
                        php8.2-curl php8.2-mbstring php8.2-fileinfo \
                        php8.2-zip php8.2-gd php8.2-xml

# Install MariaDB Database Server
sudo apt-get install -y mariadb-server mariadb-client

# Install Redis Server
sudo apt-get install -y redis-server

# Install BIND 9 DNS Server
sudo apt-get install -y bind9 bind9utils bind9-doc

# Install Supervisor Process Manager
sudo apt-get install -y supervisor

# Install Certbot for Apache
sudo apt-get install -y certbot python3-certbot-apache
```

Verify services are running:
```bash
sudo systemctl enable --now apache2 php8.2-fpm mariadb redis-server named supervisor
```

---

### Step 2: Project Directory Structure & Permissions

Create the isolated web root for `edvora.chat` (completely separated from all other hosted sites):

```bash
# Create directory tree
sudo mkdir -p /var/www/edvora.chat/{public,storage/uploads,storage/logs,backups,app,config,workers}

# Set owner and group permissions
sudo chown -R critical:www-data /var/www/edvora.chat
sudo chmod -R 775 /var/www/edvora.chat
```

---

### Step 3: MariaDB Database Setup

Login to MariaDB as root and execute:

```sql
-- Create isolated database
CREATE DATABASE IF NOT EXISTS edvora_chat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create dedicated database user
CREATE USER IF NOT EXISTS 'edvora'@'localhost' IDENTIFIED BY 'EdvoraChat_Secure2026!';

-- Grant privileges
GRANT ALL PRIVILEGES ON edvora_chat.* TO 'edvora'@'localhost';
FLUSH PRIVILEGES;
```

Verify user connection:
```bash
mariadb -uedvora -pEdvoraChat_Secure2026! -e "USE edvora_chat; SELECT DATABASE();"
```

---

### Step 4: Apache VirtualHost Configuration

Create `/etc/apache2/sites-available/edvora.chat.conf`:

```apache
<VirtualHost *:80>
    ServerName edvora.chat
    ServerAlias app.edvora.chat api.edvora.chat cdn.edvora.chat

    DocumentRoot /var/www/edvora.chat/public

    <Directory /var/www/edvora.chat/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Pass PHP scripts to PHP 8.2 FPM socket
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Force HTTPS Redirection
    RewriteEngine On
    RewriteCond %{SERVER_NAME} =edvora.chat [OR]
    RewriteCond %{SERVER_NAME} =app.edvora.chat [OR]
    RewriteCond %{SERVER_NAME} =api.edvora.chat [OR]
    RewriteCond %{SERVER_NAME} =cdn.edvora.chat
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]

    ErrorLog ${APACHE_LOG_DIR}/edvora.chat_error.log
    CustomLog ${APACHE_LOG_DIR}/edvora.chat_access.log combined
</VirtualHost>
```

Enable the site and reload Apache:
```bash
# Enable site config
sudo a2ensite edvora.chat.conf

# Test syntax (MUST return 'Syntax OK')
sudo apache2ctl configtest

# Reload Apache safely (does NOT drop connections on other hosted sites)
sudo systemctl reload apache2
```

---

### Step 5: BIND 9 Authoritative DNS Zone Setup

#### 5.1 Create Zone File `/etc/bind/db.edvora.chat`:

```bind
$TTL    604800
@       IN      SOA     ns1.edvora.chat. admin.edvora.chat. (
                              1         ; Serial
                         604800         ; Refresh
                          86400         ; Retry
                        2419200         ; Expire
                         604800 )       ; Negative Cache TTL
;
@       IN      NS      ns1.edvora.chat.
@       IN      NS      ns2.edvora.chat.
@       IN      A       166.1.2.112
www     IN      A       166.1.2.112
app     IN      A       166.1.2.112
api     IN      A       166.1.2.112
cdn     IN      A       166.1.2.112
ns1     IN      A       166.1.2.112
ns2     IN      A       166.1.2.112
```

Set permissions:
```bash
sudo chown root:bind /etc/bind/db.edvora.chat
sudo chmod 644 /etc/bind/db.edvora.chat
```

#### 5.2 Add Zone to `/etc/bind/named.conf.local`:

Append to `/etc/bind/named.conf.local`:
```bind
zone "edvora.chat" {
    type master;
    file "/etc/bind/db.edvora.chat";
};
```

#### 5.3 Verify and Reload BIND:

```bash
# Check zone syntax
sudo named-checkzone edvora.chat /etc/bind/db.edvora.chat

# Check configuration syntax
sudo named-checkconf

# Reload DNS server
sudo rndc reload
```

Test local DNS resolution:
```bash
dig @127.0.0.1 edvora.chat +short
dig @127.0.0.1 ns1.edvora.chat +short
```

---

### Step 6: Background Worker Setup (Supervisor)

#### 6.1 Create Worker Script `/var/www/edvora.chat/workers/job_runner.php`:

```php
<?php
// Supervisor worker process loop
while (true) {
    // Process queued tasks from Redis
    sleep(5);
}
```

#### 6.2 Create Supervisor Config `/etc/supervisor/conf.d/edvora-worker.conf`:

```ini
[program:edvora-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/edvora.chat/workers/job_runner.php
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=critical
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/edvora.chat/storage/logs/worker.log
stopwaitsecs=3600
```

#### 6.3 Activate Supervisor Workers:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status edvora-worker:*
```

---

### Step 7: Environment Configuration (`.env`)

Create `/var/www/edvora.chat/.env`:

```ini
APP_NAME=edvora.chat
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.edvora.chat
API_URL=https://api.edvora.chat
CDN_URL=https://cdn.edvora.chat

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=edvora_chat
DB_USERNAME=edvora
DB_PASSWORD=EdvoraChat_Secure2026!

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

LLM_ENCRYPTION_KEY=a7f4e92b8c1d3e5f6a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f
```

Set secure permissions:
```bash
chmod 600 /var/www/edvora.chat/.env
```

---

### Step 8: SSL Certificate Setup (Certbot)

Once domain nameservers (`ns1.edvora.chat`, `ns2.edvora.chat`) propagate globally:

```bash
sudo certbot --apache -d edvora.chat -d app.edvora.chat -d api.edvora.chat -d cdn.edvora.chat
```

Certbot will automatically issue Let's Encrypt TLS certificates and configure HTTPS redirects in `/etc/apache2/sites-available/edvora.chat-le-ssl.conf`.

---

## 🔍 Verification & Health Check Commands

| Test | Command | Expected Output |
|------|---------|-----------------|
| **Apache Syntax** | `sudo apache2ctl configtest` | `Syntax OK` |
| **PHP Execution** | `curl -s -H 'Host: edvora.chat' http://127.0.0.1/` | JSON output |
| **Database Access**| `mariadb -uedvora -pEdvoraChat_Secure2026! -e "USE edvora_chat;"` | Success (no error) |
| **DNS Resolution**| `dig @127.0.0.1 app.edvora.chat +short` | `166.1.2.112` |
| **Worker Status** | `sudo supervisorctl status edvora-worker:*` | `RUNNING` (4 processes) |
| **PDF Extraction**| `pdftotext -v` | Poppler version info |

---

## 🛡️ Important Safety Checklist for Shared VPS Migration

- [ ] Ensure `/var/www/edvora.chat/` is the ONLY directory written to.
- [ ] Ensure MariaDB database user `edvora` has access ONLY to `edvora_chat`.
- [ ] Never modify global `/etc/apache2/apache2.conf` or other virtual hosts.
- [ ] Always use `sudo systemctl reload apache2` instead of `restart`.
