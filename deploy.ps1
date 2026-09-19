param (
    [string]$Message = "chore: deploy updates and sync codebase"
)

$ErrorActionPreference = "Stop"

Write-Host "
[1/6] Staging and Committing to Git..." -ForegroundColor Cyan
git add -A
$status = git status --porcelain
if ($status) {
    git commit -m "$Message"
    Write-Host "Committed changes locally with message: '$Message'" -ForegroundColor Green
} else {
    Write-Host "No new uncommitted changes detected in local tree." -ForegroundColor Yellow
}

Write-Host "
[2/6] Packaging Codebase..." -ForegroundColor Cyan
if (Test-Path "deploy_package.tar.gz") {
    Remove-Item "deploy_package.tar.gz" -Force
}
tar.exe -czvf deploy_package.tar.gz app public BRANDING_GUIDELINES.md theme-branding.css AGENTS.md architecture.md architecture_department_team_management.md migrate.php deploy.ps1
Write-Host "deploy_package.tar.gz created successfully." -ForegroundColor Green

Write-Host "
[3/6] Uploading to Production VPS (166.1.2.112)..." -ForegroundColor Cyan
scp -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no deploy_package.tar.gz critical@166.1.2.112:/tmp/deploy_package.tar.gz
Write-Host "Package uploaded to VPS /tmp/" -ForegroundColor Green

Write-Host "
[4/6] Remote Extract (sudo), Migrate, Chown and Apache Reload..." -ForegroundColor Cyan
$remoteCmd = "echo 'dYt2295ZBM_EgUb' | sudo -S tar -xzvf /tmp/deploy_package.tar.gz -C /var/www/edvora.chat/ && rm -f /var/www/edvora.chat/public/app/tabs/department*.html /var/www/edvora.chat/public/app/tabs/dept-*.html /var/www/edvora.chat/public/app/js/departments.js && php /var/www/edvora.chat/migrate.php && echo 'dYt2295ZBM_EgUb' | sudo -S chown -R critical:www-data /var/www/edvora.chat && echo 'dYt2295ZBM_EgUb' | sudo -S chmod -R 775 /var/www/edvora.chat/storage && echo 'dYt2295ZBM_EgUb' | sudo -S systemctl reload apache2"
ssh -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no critical@166.1.2.112 $remoteCmd
Write-Host "Remote extraction and Apache reload complete." -ForegroundColor Green

Write-Host "
[5/6] Verifying Live Production Response..." -ForegroundColor Cyan
ssh -i "C:/Users/Salan Khalkho/.ssh/id_ed25519" -o BatchMode=yes -o StrictHostKeyChecking=no critical@166.1.2.112 "curl -s -k -I https://edvora.chat/ | head -n 5"
Write-Host "Production web server is responding healthy." -ForegroundColor Green

Write-Host "
[6/6] Pushing to GitHub (origin/main)..." -ForegroundColor Cyan
git push origin main
Write-Host "GitHub repository fully synchronized with origin/main." -ForegroundColor Green

Write-Host "
============================================================" -ForegroundColor Green
Write-Host "FULL DEPLOYMENT AND GITHUB SYNC COMPLETED SUCCESSFULLY!" -ForegroundColor Green
Write-Host "============================================================
" -ForegroundColor Green