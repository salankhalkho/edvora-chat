(function () {
    'use strict';

    // 1. Extract bot token from script tag
    var currentScript = document.currentScript || (function () {
        var scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    })();

    var botToken = currentScript ? currentScript.getAttribute('data-bot-token') : null;
    if (!botToken && typeof window !== 'undefined' && window.location) {
        var tempParams = new URLSearchParams(window.location.search);
        botToken = tempParams.get('token') || tempParams.get('bot_token');
    }
    if (!botToken) {
        console.error('[Edvora Chat] Missing data-bot-token attribute on script tag.');
        return;
    }

    var isTestAttr = currentScript ? (currentScript.getAttribute('data-is-test') === '1' || currentScript.getAttribute('data-is-test') === 'true') : false;

    // Check URL parameters if embedded in preview/standalone page
    var urlParams = typeof window !== 'undefined' && window.location ? new URLSearchParams(window.location.search) : new URLSearchParams();
    var isTest = isTestAttr || (urlParams.get('is_test') === '1' || urlParams.get('test') === '1');

    var apiBaseUrl = currentScript ? currentScript.src.substring(0, currentScript.src.lastIndexOf('/')) : '';
    if (!apiBaseUrl || apiBaseUrl === '') {
        apiBaseUrl = 'https://edvora.chat';
    }

    // 2. Generate or fetch persistent visitor UUID
    var visitorKey = 'edvora_visitor_' + botToken + (isTest ? '_test' : '');
    var visitorId = localStorage.getItem(visitorKey);
    if (!visitorId) {
        visitorId = (isTest ? 'test_' : 'v_') + Math.random().toString(36).substring(2, 11) + '_' + Date.now();
        localStorage.setItem(visitorKey, visitorId);
    }
    var currentConversationId = null;

    var config = {
        name: 'AI Admissions Assistant',
        primaryColor: '#2563EB',
        welcomeMessage: 'Hi there! 👋 How can I assist you with admissions today?',
        leadCaptureEnabled: true
    };

    // 3. Inject CSS Styles
    var style = document.createElement('style');
    style.innerHTML = `
        .edvora-chat-widget { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; position: fixed; bottom: 20px; right: 20px; z-index: 999999; }
        .edvora-trigger-btn { width: 60px; height: 60px; border-radius: 50%; background: #2563EB; color: #ffffff; border: none; cursor: pointer; box-shadow: 0 10px 25px rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .edvora-trigger-btn:hover { transform: scale(1.08); box-shadow: 0 12px 30px rgba(0,0,0,0.3); }
        .edvora-chat-window { display: none; position: fixed; bottom: 90px; right: 20px; width: 380px; height: 550px; max-width: calc(100vw - 40px); max-height: calc(100vh - 120px); background: #ffffff; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); flex-direction: column; overflow: hidden; border: 1px solid #E5E7EB; z-index: 999999; }
        .edvora-chat-window.open { display: flex; animation: edvoraSlideUp 0.3s ease-out; }
        @keyframes edvoraSlideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .edvora-header { background: #2563EB; color: #ffffff; padding: 8px 12px; height: 48px; min-height: 48px; box-sizing: border-box; display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-shrink: 0; }
        .edvora-header-left { display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1; }
        .edvora-header-logo-box { width: 28px; height: 28px; border-radius: 4px; display: none; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; background: #ffffff; border: 1px solid #ffffff; box-shadow: 0 1px 2px rgba(0,0,0,0.12); }
        .edvora-header-logo-box img { max-height: 28px; max-width: 65px; object-fit: contain; }
        .edvora-header-text { display: flex; flex-direction: column; min-width: 0; justify-content: center; }
        .edvora-header-title { font-size: 13px; font-weight: 700; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .edvora-header-sub { font-size: 10px; opacity: 0.85; margin-top: 1px; line-height: 1.1; display: flex; align-items: center; gap: 4px; }
        .edvora-online-dot { width: 6px; height: 6px; border-radius: 50%; background: #4ade80; display: inline-block; }
        .edvora-close-btn { background: none; border: none; color: #ffffff; font-size: 18px; cursor: pointer; opacity: 0.75; padding: 4px; line-height: 1; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: opacity 0.15s ease; }
        .edvora-close-btn:hover { opacity: 1; }
        .edvora-messages { flex: 1; padding: 10px 8px 10px 6px; overflow-y: auto; background: #F9FAFB; display: flex; flex-direction: column; gap: 10px; }
        .edvora-msg-row { display: flex; align-items: flex-start; gap: 5px; width: 100%; box-sizing: border-box; }
        .edvora-msg-row.assistant { align-self: flex-start; max-width: 98%; }
        .edvora-msg-row.user { align-self: flex-end; flex-direction: row-reverse; max-width: 85%; }
        .edvora-msg-avatar { width: 24px; height: 24px; border-radius: 50%; overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; margin-top: 2px; }
        .edvora-msg-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block; }
        .edvora-msg { max-width: 100%; padding: 6px 10px; border-radius: 12px; font-size: 13px; line-height: 1.45; word-wrap: break-word; }
        .edvora-msg.assistant { background: #ffffff; color: #1F2937; align-self: flex-start; border: 1px solid #E5E7EB; border-top-left-radius: 2px; }
        .edvora-msg.user { background: #2563EB; color: #ffffff; align-self: flex-end; border-top-right-radius: 2px; max-width: 85%; }
        .edvora-input-area { padding: 12px; background: #ffffff; border-top: 1px solid #E5E7EB; display: flex; gap: 8px; }
        .edvora-input-area input { flex: 1; padding: 10px 14px; border: 1px solid #D1D5DB; border-radius: 24px; font-size: 14px; outline: none; }
        .edvora-input-area input:focus { border-color: #2563EB; }
        .edvora-send-btn { background: #2563EB; color: #ffffff; border: none; width: 38px; height: 38px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 16px; transition: transform 0.15s ease, background 0.15s ease; flex-shrink: 0; }
        .edvora-send-btn:hover { transform: scale(1.08); }
        .edvora-send-btn svg { width: 15px; height: 15px; fill: currentColor; margin-left: 2px; display: block; }
        .edvora-lead-banner { background: #EFF6FF; border: 1px solid #BFDBFE; color: #1E40AF; padding: 12px; border-radius: 8px; font-size: 13px; margin-top: 8px; }
        .edvora-lead-btn { background: #2563EB; color: white; border: none; padding: 6px 12px; border-radius: 4px; margin-top: 8px; cursor: pointer; font-weight: 500; }
        .edvora-typing-bubble { display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 6px 11px; background: #E6F7D2; border: 1px solid #B9D7C7; border-radius: 10px 10px 10px 2px; min-height: 28px; box-sizing: border-box; }
        .edvora-typing-dot { width: 5px; height: 5px; border-radius: 50%; background-color: #063D3B; display: inline-block; animation: typingBounce 1.3s infinite ease-in-out; }
        .edvora-typing-dot:nth-child(1) { animation-delay: 0s; }
        .edvora-typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .edvora-typing-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingBounce { 0%, 60%, 100% { transform: translateY(0); opacity: 0.35; } 30% { transform: translateY(-4px); opacity: 1; } }
    `;
    document.head.appendChild(style);

    // 4. Create DOM elements
    var widgetContainer = document.createElement('div');
    widgetContainer.className = 'edvora-chat-widget';

    widgetContainer.innerHTML = `
        <button class="edvora-trigger-btn" id="edvoraTrigger" aria-label="Open Chat">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
        </button>
        <div class="edvora-chat-window" id="edvoraWindow">
            <div class="edvora-header" id="edvoraHeader">
                <div class="edvora-header-left">
                    <div class="edvora-header-logo-box" id="edvoraHeaderLogoBox" style="display:none; width:28px; height:28px; border-radius:4px; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden; background:transparent;">
                        <img id="edvoraHeaderLogo" src="" alt="Logo" style="max-height:28px; max-width:65px; object-fit:contain; display:block;" />
                    </div>
                    <div class="edvora-header-text">
                        <span class="edvora-header-title" id="edvoraTitle">College Assistant</span>
                        <span class="edvora-header-sub" id="edvoraSubtitle"><span class="edvora-online-dot"></span>Online Now</span>
                    </div>
                </div>
                <button class="edvora-close-btn" id="edvoraClose" aria-label="Close Chat">&times;</button>
            </div>
            <div class="edvora-messages" id="edvoraMessages"></div>

            <div class="edvora-input-area">
                <input type="text" id="edvoraInput" placeholder="Ask a question..." autocomplete="off" />
                <button class="edvora-send-btn" id="edvoraSend" aria-label="Send message"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg></button>
            </div>
        </div>
    `;
    document.body.appendChild(widgetContainer);

    var triggerBtn = document.getElementById('edvoraTrigger');
    var chatWindow = document.getElementById('edvoraWindow');
    var closeBtn = document.getElementById('edvoraClose');
    var messagesContainer = document.getElementById('edvoraMessages');
    var inputField = document.getElementById('edvoraInput');
    var sendBtn = document.getElementById('edvoraSend');

    // 5. Toggle Window
    triggerBtn.onclick = function () {
        chatWindow.classList.toggle('open');
    };
    closeBtn.onclick = function () {
        chatWindow.classList.remove('open');
    };

    if (isTest) {
        chatWindow.classList.add('open');
    }

    // 6. Fetch Widget Config
    var configUrl = apiBaseUrl + '/v1/widget/config/' + botToken;
    fetch(configUrl)
        .then(function (res) { return res.json(); })
        .then(function (res) {
            if (res.status === 'success' && res.data) {
                config = res.data;
                var cust = config.customization || {};

                var titleEl = document.getElementById('edvoraTitle');
                if (titleEl) {
                    titleEl.innerText = cust.header_bot_name || config.name || config.organization_name || 'College Assistant';
                    if (cust.header_text_color) titleEl.style.color = cust.header_text_color;
                }
                var subEl = document.getElementById('edvoraSubtitle');
                if (subEl) {
                    var subText = cust.header_subtitle || 'Online Now';
                    subEl.innerHTML = '<span class="edvora-online-dot"></span>' + subText;
                    if (cust.header_text_color) subEl.style.color = cust.header_text_color;
                }

                var pColor = cust.header_bg || config.primary_color || config.primaryColor || '#6366F1';
                var headerTextColor = cust.header_text_color || '#ffffff';
                var sColor = config.secondary_color || '#38BDF8';
                var mode = cust.theme || config.theme_mode || 'light';

                var shadowMap = {
                    none: 'none',
                    soft: '0 8px 24px -4px rgba(0, 0, 0, 0.22), 0 4px 12px -2px rgba(0, 0, 0, 0.14)',
                    medium: '0 18px 38px -6px rgba(0, 0, 0, 0.38), 0 8px 18px -4px rgba(0, 0, 0, 0.24)',
                    deep: '0 30px 65px -8px rgba(0, 0, 0, 0.58), 0 16px 30px -6px rgba(0, 0, 0, 0.38)'
                };

                var modernSvg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block; vertical-align:middle;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
                var iconMap = {
                    chat: '💬', modern_chat: modernSvg, robot: '🤖', sparkles: '✨', headset: '🎧', star: '⭐'
                };

                // Position widget container & window
                var pos = cust.launcher_position || 'bottom-right';
                if (widgetContainer) {
                    widgetContainer.style.top = pos.startsWith('top') ? '20px' : 'auto';
                    widgetContainer.style.bottom = pos.startsWith('bottom') ? '20px' : 'auto';
                    widgetContainer.style.left = pos.endsWith('left') ? '20px' : 'auto';
                    widgetContainer.style.right = pos.endsWith('right') ? '20px' : 'auto';
                }

                if (triggerBtn) {
                    var lBg = cust.launcher_bg || pColor;
                    var lIconCol = cust.launcher_icon_color || '#ffffff';
                    var lSize = (cust.launcher_size || 60) + 'px';
                    var lStyle = cust.launcher_style || 'circle';
                    var lIcon = iconMap[cust.launcher_icon] || modernSvg;

                    triggerBtn.style.background = lBg;
                    triggerBtn.style.color = lIconCol;
                    triggerBtn.style.height = lSize;

                    if (lStyle === 'pill') {
                        triggerBtn.style.width = 'auto';
                        triggerBtn.style.padding = '0 18px';
                        triggerBtn.style.borderRadius = '30px';
                        triggerBtn.innerHTML = '<span style="font-size:16px;">' + lIcon + '</span> <span style="font-weight:700; font-size:13px; margin-left:6px; color:' + lIconCol + ';">' + (cust.launcher_text || 'Ask AI') + '</span>';
                    } else if (lStyle === 'square') {
                        triggerBtn.style.width = lSize;
                        triggerBtn.style.borderRadius = '12px';
                        triggerBtn.innerHTML = '<span style="font-size:20px;">' + lIcon + '</span>';
                    } else {
                        triggerBtn.style.width = lSize;
                        triggerBtn.style.borderRadius = '50%';
                        triggerBtn.innerHTML = '<span style="font-size:20px;">' + lIcon + '</span>';
                    }
                }

                if (chatWindow) {
                    var winBg = cust.window_bg_color || (mode === 'light' ? '#ffffff' : '#0f172a');
                    var winBorderCol = cust.window_border_color || '#e2e8f0';
                    var winBorderW = (cust.window_border_width !== undefined ? cust.window_border_width : 1) + 'px';
                    var winRadius = (cust.window_border_radius !== undefined ? cust.window_border_radius : 16) + 'px';
                    var winWidth = (cust.window_width !== undefined ? cust.window_width : 380) + 'px';
                    var winHeight = (cust.window_height !== undefined ? cust.window_height : 550) + 'px';
                    var winShadow = shadowMap[cust.window_shadow] || shadowMap.soft;

                    chatWindow.style.background = winBg;
                    chatWindow.style.borderColor = winBorderCol;
                    chatWindow.style.borderWidth = winBorderW;
                    chatWindow.style.borderStyle = 'solid';
                    chatWindow.style.borderRadius = winRadius;
                    chatWindow.style.width = winWidth;
                    chatWindow.style.height = winHeight;
                    chatWindow.style.boxShadow = winShadow;

                    chatWindow.style.top = pos.startsWith('top') ? '90px' : 'auto';
                    chatWindow.style.bottom = pos.startsWith('bottom') ? '90px' : 'auto';
                    chatWindow.style.left = pos.endsWith('left') ? '20px' : 'auto';
                    chatWindow.style.right = pos.endsWith('right') ? '20px' : 'auto';
                }

                // Resolve avatar / header logo
                var resolvedAvatarSrc = (cust.avatar_type === 'upload' && cust.avatar_url) ? cust.avatar_url : ('/avatars/avatar' + (cust.avatar_preset || 1) + '.png');
                if (resolvedAvatarSrc.startsWith('/')) {
                    resolvedAvatarSrc = apiBaseUrl + resolvedAvatarSrc;
                }
                config._resolvedAvatarSrc = resolvedAvatarSrc;

                var headerEl = document.getElementById('edvoraHeader');
                if (headerEl) {
                    headerEl.style.background = pColor;
                    headerEl.style.color = headerTextColor;
                    var logoUrl = (cust && cust.header_logo_url) ? cust.header_logo_url : (config.org_logo || null);
                    // If no explicit header logo, fallback to avatar if header or both is active
                    if (!logoUrl && (cust.avatar_location === 'header' || cust.avatar_location === 'both')) {
                        logoUrl = resolvedAvatarSrc;
                    } else if (!logoUrl) {
                        logoUrl = '/default_logo.png';
                    }
                    var logoBox = document.getElementById('edvoraHeaderLogoBox');
                    var logoImg = document.getElementById('edvoraHeaderLogo');
                    if (logoUrl) {
                        if (logoUrl.startsWith('/')) {
                            logoUrl = apiBaseUrl + logoUrl;
                        }
                        if (logoImg) logoImg.src = logoUrl;
                        if (logoBox) logoBox.style.display = 'flex';
                    } else if (logoBox) {
                        logoBox.style.display = 'none';
                    }
                }

                if (closeBtn) closeBtn.style.color = headerTextColor;

                if (messagesContainer && cust.message_area_bg) {
                    messagesContainer.style.background = cust.message_area_bg;
                }

                if (inputField) {
                    if (cust.input_bg) inputField.style.background = cust.input_bg;
                    if (cust.input_border_color) inputField.style.borderColor = cust.input_border_color;
                    if (cust.input_text_color) inputField.style.color = cust.input_text_color;
                    if (cust.input_border_radius !== undefined) inputField.style.borderRadius = cust.input_border_radius + 'px';
                    if (cust.input_placeholder) inputField.placeholder = cust.input_placeholder;
                }

                if (sendBtn) {
                    if (cust.send_btn_bg) sendBtn.style.background = cust.send_btn_bg;
                    if (cust.send_btn_icon_color) sendBtn.style.color = cust.send_btn_icon_color;
                }

                messagesContainer.innerHTML = '';
                var defaultWelcome = "Hi there! 👋 Welcome to " + (config.organization_name || "our institution") + ". How can I assist you with admissions, programs, or campus life today?";
                var welcomeText = cust.welcome_message || config.welcome_message || config.welcomeMessage || defaultWelcome;
                appendMessage('assistant', welcomeText);

                // Render Quick Prompt Chips strictly if configured by the institution
                var chipsList = (config.quick_chips && Array.isArray(config.quick_chips) && config.quick_chips.length > 0) ? config.quick_chips : [];
                if (chipsList.length > 0) {
                    renderPromptChips(chipsList, cust);
                }


            }
        }).catch(function () {
            appendMessage('assistant', 'Welcome! How can I help you today?');
        });

    function renderPromptChips(chips, cust) {
        var div = document.createElement('div');
        div.style.cssText = 'display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; padding-left: 28px;';
        var cBorder = (cust && cust.chip_border_color) ? cust.chip_border_color : '#6366F1';
        var cText = (cust && cust.chip_text_color) ? cust.chip_text_color : '#6366F1';
        var cBg = (cust && cust.chip_bg) ? cust.chip_bg : 'transparent';
        var cRadius = (cust && cust.chip_border_radius !== undefined) ? cust.chip_border_radius + 'px' : '14px';

        chips.forEach(function (chip) {
            var chipLabel = (typeof chip === 'object' && chip !== null) ? (chip.label || chip.message || JSON.stringify(chip)) : String(chip);
            var chipMessage = (typeof chip === 'object' && chip !== null) ? (chip.message || chip.label || '') : String(chip);

            var btn = document.createElement('button');
            btn.style.cssText = 'background: ' + cBg + '; color: ' + cText + '; border: 1px solid ' + cBorder + '; padding: 5px 11px; border-radius: ' + cRadius + '; font-size: 11px; font-weight: 500; cursor: pointer; transition: all 0.2s;';
            btn.innerText = chipLabel;
            btn.onclick = function () {
                var queryText = chipMessage || chipLabel;
                if (queryText.toLowerCase().indexOf('scholarship') !== -1) {
                    appendMessage('user', chipLabel);
                    startScholarshipEvaluation();
                } else if (queryText.toLowerCase().indexOf('callback') !== -1 || queryText.toLowerCase().indexOf('counselor') !== -1 || queryText.toLowerCase().indexOf('call') !== -1) {
                    appendMessage('user', chipLabel);
                    startCounselorCallbackForm();
                } else {
                    inputField.value = queryText;
                    sendMessage();
                }
            };
            div.appendChild(btn);
        });
        messagesContainer.appendChild(div);
    }

    // 7. Markdown Formatting Helper & Message Appending
    function formatMarkdown(text) {
        if (!text) return '';

        // 1. XSS Escaping
        var html = String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // 2. Bold & Italics
        html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__(.*?)__/g, '<strong>$1</strong>');
        html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
        html = html.replace(/_(.*?)_/g, '<em>$1</em>');

        // 3. Headings (### Title, ## Title, # Title)
        html = html.replace(/^### (.*$)/gim, '<div style="font-weight:700; font-size:12.5px; color:#1E293B; margin:6px 0 2px 0;">$1</div>');
        html = html.replace(/^## (.*$)/gim, '<div style="font-weight:700; font-size:13px; color:#1E293B; margin:8px 0 3px 0;">$1</div>');
        html = html.replace(/^# (.*$)/gim, '<div style="font-weight:700; font-size:14px; color:#1E293B; margin:10px 0 4px 0;">$1</div>');

        // 4. Line-by-line structured layout with compact category headers & lists
        var lines = html.split('\n');
        var inList = false;
        var listType = null;
        var processedLines = [];

        for (var i = 0; i < lines.length; i++) {
            var rawLine = lines[i];
            var trimmed = rawLine.trim();

            var bulletMatch = trimmed.match(/^[-*•]\s+(.*)/);
            var numMatch = trimmed.match(/^(\d+)\.\s+(.*)/);

            // Check if this line is an empty line inside a list
            if (trimmed === '') {
                var nextIsList = false;
                for (var j = i + 1; j < lines.length; j++) {
                    var peekTrim = lines[j].trim();
                    if (peekTrim !== '') {
                        if (peekTrim.match(/^[-*•]\s+/) || peekTrim.match(/^\d+\.\s+/)) {
                            nextIsList = true;
                        }
                        break;
                    }
                }
                if (inList && nextIsList) {
                    continue;
                }
                if (inList) {
                    processedLines.push(listType === 'ul' ? '</ul>' : '</ol>');
                    inList = false;
                    listType = null;
                }
                processedLines.push('');
                continue;
            }

            if (bulletMatch) {
                if (!inList || listType !== 'ul') {
                    if (inList) processedLines.push(listType === 'ul' ? '</ul>' : '</ol>');
                    inList = true;
                    listType = 'ul';
                    processedLines.push('<ul style="margin: 2px 0 5px 0; padding-left: 14px; list-style-type: disc;">');
                }

                // Format inline duration like (4 Years) or (18 Months) with non-breaking pill style
                var itemContent = bulletMatch[1].replace(/\((\d+[^)]*)\)/g, '<span style="font-size:10px; font-weight:600; color:#475569; background:#F1F5F9; border:1px solid #CBD5E1; padding:0 5px; border-radius:3px; margin-left:4px; white-space:nowrap; display:inline-block;">$1</span>');

                processedLines.push('<li style="margin-bottom: 2px; line-height: 1.35;">' + itemContent + '</li>');
            } else if (numMatch) {
                if (!inList || listType !== 'ol') {
                    if (inList) processedLines.push(listType === 'ul' ? '</ul>' : '</ol>');
                    inList = true;
                    listType = 'ol';
                    processedLines.push('<ol style="margin: 2px 0 5px 0; padding-left: 14px;">');
                }
                var itemContentNum = numMatch[2].replace(/\((\d+[^)]*)\)/g, '<span style="font-size:10px; font-weight:600; color:#475569; background:#F1F5F9; border:1px solid #CBD5E1; padding:0 5px; border-radius:3px; margin-left:4px; white-space:nowrap; display:inline-block;">$1</span>');
                processedLines.push('<li style="margin-bottom: 2px; line-height: 1.35;">' + itemContentNum + '</li>');
            } else {
                if (inList) {
                    processedLines.push(listType === 'ul' ? '</ul>' : '</ol>');
                    inList = false;
                    listType = null;
                }

                // Cleanly match category sub-headings like "<strong>Undergraduate:</strong>" or "Undergraduate Programs:"
                var strippedTag = trimmed.replace(/<\/?strong>/gi, '').replace(/[:*]/g, '').trim();
                var isCatHeader = /^(?:[A-Za-z\s&]{2,35}(?:Programs?|Courses?|Degrees?|Certificates?|Executive|Undergraduate|Postgraduate|Doctoral|Diploma|Specializations?)|Undergraduate|Postgraduate|Doctoral|Executive|Certificates?)$/i.test(strippedTag);

                if (isCatHeader && trimmed.length < 50) {
                    processedLines.push('<div style="font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #475569; margin: 8px 0 2px 0; display: flex; align-items: center; gap: 4px;"><span style="display:inline-block; width:5px; height:5px; border-radius:50%; background:#2563EB;"></span> ' + strippedTag + '</div>');
                } else {
                    processedLines.push(trimmed);
                }
            }
        }

        if (inList) {
            processedLines.push(listType === 'ul' ? '</ul>' : '</ol>');
        }

        // 5. Build final HTML without inserting <br> inside list tags
        var output = [];
        for (var k = 0; k < processedLines.length; k++) {
            var pl = processedLines[k];
            if (pl === '') {
                // Only push break if previous item was regular text
                if (output.length > 0 && !output[output.length - 1].endsWith('</ul>') && !output[output.length - 1].endsWith('</ol>') && !output[output.length - 1].startsWith('<div style="font-size: 10px')) {
                    output.push('<br>');
                }
            } else if (pl.startsWith('<ul') || pl.startsWith('<ol') || pl.startsWith('<li') || pl === '</ul>' || pl === '</ol>' || pl.startsWith('<div style="font-size: 10px')) {
                output.push(pl);
            } else {
                // Regular text paragraph line
                if (output.length > 0 && !output[output.length - 1].endsWith('</ul>') && !output[output.length - 1].endsWith('</ol>') && output[output.length - 1] !== '<br>' && !output[output.length - 1].startsWith('<div style="font-size: 10px')) {
                    output.push('<br>');
                }
                output.push(pl);
            }
        }

        html = output.join('');

        // 6. Links: [text](url)
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" style="color: #2563EB; text-decoration: underline;">$1</a>');

        // 7. Clean up any accidental double <br>
        html = html.replace(/(<br>\s*){2,}/gi, '<br>');

        return html;
    }

    function appendMessage(role, text) {
        var cust = (config && config.customization) ? config.customization : {};
        var showBubbleAv = (role === 'assistant') && (cust.avatar_location === 'bubbles' || cust.avatar_location === 'both' || !cust.avatar_location);
        var avatarSrc = config._resolvedAvatarSrc || (apiBaseUrl + '/avatars/avatar1.png');

        var rowDiv = document.createElement('div');
        rowDiv.className = 'edvora-msg-row ' + role;

        if (role === 'assistant' && showBubbleAv) {
            var avDiv = document.createElement('div');
            avDiv.className = 'edvora-msg-avatar';
            var avImg = document.createElement('img');
            avImg.src = avatarSrc;
            avImg.alt = 'Bot Avatar';
            avDiv.appendChild(avImg);
            rowDiv.appendChild(avDiv);
        }

        var msgDiv = document.createElement('div');
        msgDiv.className = 'edvora-msg ' + role;
        if (role === 'assistant') {
            msgDiv.innerHTML = formatMarkdown(text);
        } else {
            msgDiv.innerText = text;
        }

        // Apply custom bubble styling
        if (role === 'assistant') {
            if (cust.bot_bubble_bg) msgDiv.style.background = cust.bot_bubble_bg;
            if (cust.bot_bubble_text) msgDiv.style.color = cust.bot_bubble_text;
            if (cust.bot_bubble_radius !== undefined) {
                var br = cust.bot_bubble_radius;
                msgDiv.style.borderRadius = br + 'px ' + br + 'px ' + br + 'px 3px';
            }
            if (cust.message_font_size) msgDiv.style.fontSize = cust.message_font_size + 'px';
        } else if (role === 'user') {
            var userBg = cust.user_bubble_bg || cust.header_bg || config.primary_color || config.primaryColor || '#2563EB';
            var userText = cust.user_bubble_text || '#ffffff';
            msgDiv.style.background = userBg;
            msgDiv.style.color = userText;
            if (cust.user_bubble_radius !== undefined) {
                var ur = cust.user_bubble_radius;
                msgDiv.style.borderRadius = ur + 'px ' + ur + 'px 3px ' + ur + 'px';
            }
            if (cust.message_font_size) msgDiv.style.fontSize = cust.message_font_size + 'px';
        }

        rowDiv.appendChild(msgDiv);
        messagesContainer.appendChild(rowDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // 8. Typing Indicator Helpers
    function showTypingIndicator() {
        if (document.getElementById('edvoraTyping')) return;
        var cust = (config && config.customization) ? config.customization : {};
        var showBubbleAv = (cust.avatar_location === 'bubbles' || cust.avatar_location === 'both' || !cust.avatar_location);
        var avatarSrc = config._resolvedAvatarSrc || (apiBaseUrl + '/avatars/avatar1.png');

        var typingRow = document.createElement('div');
        typingRow.className = 'edvora-msg-row assistant';
        typingRow.id = 'edvoraTyping';

        if (showBubbleAv) {
            var avDiv = document.createElement('div');
            avDiv.className = 'edvora-msg-avatar';
            var avImg = document.createElement('img');
            avImg.src = avatarSrc;
            avImg.alt = 'Bot Avatar';
            avDiv.appendChild(avImg);
            typingRow.appendChild(avDiv);
        }

        var typingDiv = document.createElement('div');
        typingDiv.className = 'edvora-msg assistant edvora-typing-bubble';
        typingDiv.innerHTML = '<span class="edvora-typing-dot"></span><span class="edvora-typing-dot"></span><span class="edvora-typing-dot"></span>';
        if (cust.bot_bubble_bg) typingDiv.style.background = cust.bot_bubble_bg;
        if (cust.bot_bubble_radius !== undefined) {
            var br = cust.bot_bubble_radius;
            typingDiv.style.borderRadius = br + 'px ' + br + 'px ' + br + 'px 3px';
        }

        typingRow.appendChild(typingDiv);
        messagesContainer.appendChild(typingRow);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function removeTypingIndicator() {
        var typing = document.getElementById('edvoraTyping');
        if (typing) typing.remove();
    }

    // 9. Send Message Handler
    function sendMessage() {
        var text = inputField.value.trim();
        if (!text) return;

        appendMessage('user', text);
        inputField.value = '';

        showTypingIndicator();

        fetch(apiBaseUrl + '/v1/chat/completions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                bot_token: botToken,
                visitor_id: visitorId,
                message: text,
                is_test: isTest ? 1 : 0,
                page_url: window.location.href,
                page_title: document.title
            })
        })
        .then(function (res) { return res.json(); })
        .then(function (res) {
            removeTypingIndicator();

            if (res.status === 'success' && res.data) {
                if (res.data.conversation_id) {
                    currentConversationId = res.data.conversation_id;
                }
                if (res.data.response && res.data.response.trim() !== '') {
                    appendMessage('assistant', res.data.response);
                }

                if (res.data.lead_capture_trigger) {
                    renderLeadBanner(res.data.lead_capture_trigger);
                }

                // If follow-up provoking question is provided, display it as a separate bubble with natural typing delay
                if (res.data.follow_up_message) {
                    setTimeout(function () {
                        showTypingIndicator();
                        setTimeout(function () {
                            removeTypingIndicator();
                            appendMessage('assistant', res.data.follow_up_message);
                        }, 900);
                    }, 800);
                }
            } else {
                appendMessage('assistant', 'Sorry, I am having trouble connecting right now. Please try again.');
            }
        })
        .catch(function () {
            removeTypingIndicator();
            appendMessage('assistant', 'Network error. Please check your internet connection.');
        });
    }

    // --- LocalStorage Lead State Persistence ---
    function getStoredLead() {
        try {
            var raw = localStorage.getItem('edvora_lead_captured_' + botToken);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function saveStoredLead(data) {
        try {
            var email = data.email || '';
            var phone = data.phone || '';
            var maskedEmail = email;
            if (email.indexOf('@') !== -1) {
                var p = email.split('@');
                maskedEmail = p[0].substring(0, 2) + '***@' + p[1];
            }
            var maskedPhone = phone.length > 4 ? (phone.substring(0, 2) + '******' + phone.substring(phone.length - 2)) : phone;

            var payload = {
                name: data.name,
                email: email,
                phone: phone,
                masked_email: maskedEmail,
                masked_phone: maskedPhone,
                captured_at: new Date().toISOString()
            };
            localStorage.setItem('edvora_lead_captured_' + botToken, JSON.stringify(payload));
            return payload;
        } catch (e) {
            return data;
        }
    }

    // --- Lead Banner / Asset Delivery Form ---
    function renderLeadBanner(trigger) {
        if (!trigger) return;

        if (trigger.type === 'counselor_callback') {
            startCounselorCallbackForm();
            return;
        }

        if (trigger.type === 'campus_tour') {
            startCampusTourForm();
            return;
        }

        if (trigger.type === 'scholarship_eval') {
            startScholarshipEvaluation();
            return;
        }

        var stored = getStoredLead();

        // If student is ALREADY captured, fulfill instantly without showing form
        if (stored && stored.email) {
            if (trigger.asset_id) {
                fetch(apiBaseUrl + '/v1/assets/' + trigger.asset_id + '/deliver', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        bot_token: botToken,
                        email: stored.email,
                        name: stored.name
                    })
                }).catch(function () {});
            }

            appendMessage('assistant', '✅ I have dispatched ' + trigger.headline + ' to your email on file (' + (stored.masked_email || stored.email) + '). Please check your inbox shortly!');
            return;
        }

        // Show full 3-field form for fresh visitors
        var banner = document.createElement('div');
        banner.className = 'edvora-lead-banner';
        banner.style.cssText = 'background: #EFF6FF; border: 1.5px solid #93C5FD; border-radius: 12px; padding: 14px; margin-top: 8px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.08); font-size: 12px;';
        banner.innerHTML = `
            <div style="display:flex; align-items:center; gap:6px; margin-bottom:6px;">
                <span style="font-size:16px;">📥</span>
                <strong style="color: #1E3A8A; font-size: 13px;">${trigger.headline}</strong>
            </div>
            <div style="margin-bottom: 10px; font-size: 11px; color: #475569; line-height: 1.4;">${trigger.description}</div>
            <form style="display: flex; flex-direction: column; gap: 7px;" onsubmit="return false;">
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:2px;">Full Name *</label>
                    <input type="text" placeholder="e.g. Ananya Sharma" required class="edvora-lead-input" style="width:100%; box-sizing:border-box; padding: 7px 10px; font-size: 12px; border: 1px solid #CBD5E1; border-radius: 6px; outline: none; background:#fff;" />
                </div>
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:2px;">Email Address *</label>
                    <input type="email" placeholder="e.g. ananya@gmail.com" required class="edvora-lead-input" style="width:100%; box-sizing:border-box; padding: 7px 10px; font-size: 12px; border: 1px solid #CBD5E1; border-radius: 6px; outline: none; background:#fff;" />
                </div>
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:2px;">Phone / WhatsApp Number *</label>
                    <input type="tel" placeholder="e.g. 9876543210" required class="edvora-lead-input" style="width:100%; box-sizing:border-box; padding: 7px 10px; font-size: 12px; border: 1px solid #CBD5E1; border-radius: 6px; outline: none; background:#fff;" />
                </div>
                <button type="button" class="edvora-lead-btn" style="background: #2563EB; color: white; border: none; padding: 8px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; margin-top: 4px;">
                    Send Document Now &rarr;
                </button>
                <div style="font-size:10px; color:#64748B; text-align:center; margin-top:2px;">🔒 No spam. We only use your details to deliver requested information.</div>
            </form>
        `;

        var inputs = banner.querySelectorAll('.edvora-lead-input');
        var submitBtn = banner.querySelector('button');

        submitBtn.onclick = function () {
            var name = inputs[0].value.trim();
            var email = inputs[1].value.trim();
            var phone = inputs[2].value.trim();

            if (!name || !email || !phone) {
                alert('Please enter your Full Name, Email Address, and Phone Number.');
                return;
            }

            submitBtn.innerText = 'Dispatching document...';
            submitBtn.disabled = true;

            fetch(apiBaseUrl + '/v1/leads', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bot_token: botToken,
                    name: name,
                    email: email,
                    phone: phone,
                    lead_type: 'asset',
                    program_interest: trigger.headline,
                    department_id: deptId || null,
                    conversation_id: currentConversationId,
                    visitor_id: visitorId
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res.status === 'success') {
                    var saved = saveStoredLead({ name: name, email: email, phone: phone });

                    // Deliver asset via email if asset_id present
                    if (trigger.asset_id) {
                        fetch(apiBaseUrl + '/v1/assets/' + trigger.asset_id + '/deliver', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                bot_token: botToken,
                                email: email,
                                name: name
                            })
                        }).catch(function () {});
                    }

                    banner.innerHTML = `
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-size:22px;">✅</span>
                            <div>
                                <strong style="color: #166534; font-size:13px;">Document Dispatched!</strong>
                                <div style="font-size: 11px; color: #15803D; margin-top:2px;">
                                    We have sent <strong>${trigger.headline}</strong> to <strong>${saved.masked_email || email}</strong>. Please check your inbox!
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    alert(res.message || 'Submission failed');
                    submitBtn.innerText = 'Send Document Now →';
                    submitBtn.disabled = false;
                }
            })
            .catch(function () {
                alert('Network error. Please try again.');
                submitBtn.innerText = 'Send Document Now →';
                submitBtn.disabled = false;
            });
        };

        messagesContainer.appendChild(banner);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // --- Interactive Counselor Callback Card ---
    function startCounselorCallbackForm() {
        var stored = getStoredLead();

        // Returning captured visitor: Instant 1-Click Callback
        if (stored && (stored.phone || stored.email)) {
            var quickCard = document.createElement('div');
            quickCard.className = 'edvora-callback-card';
            quickCard.style.cssText = 'background: #ffffff; border: 1.5px solid #059669; border-radius: 12px; padding: 14px; margin-top: 8px; box-shadow: 0 4px 14px rgba(5, 150, 105, 0.12); font-size: 12px;';
            quickCard.innerHTML = `
                <div style="display:flex; align-items:center; gap:6px; margin-bottom:8px;">
                    <span style="font-size:16px;">📞</span>
                    <strong style="color:#065F46; font-size:13px;">Confirm Counselor Callback</strong>
                </div>
                <div style="color:#475569; font-size:11px; margin-bottom:10px;">
                    We will connect you with an admissions counselor at <strong>${stored.masked_phone || stored.phone}</strong>.
                </div>
                <div style="margin-bottom:8px;">
                    <label style="display:block; font-size:10px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:3px;">Select Preferred Time</label>
                    <select id="edvoraQuickSlot" style="width:100%; box-sizing:border-box; padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none; background:#fff;">
                        <option value="Immediate (ASAP)">⚡ ASAP (Within 15 mins)</option>
                        <option value="Morning (9 AM - 12 PM)">🌅 Morning (9 AM – 12 PM)</option>
                        <option value="Afternoon (12 PM - 4 PM)">☀️ Afternoon (12 PM – 4 PM)</option>
                        <option value="Evening (4 PM - 7 PM)">🌇 Evening (4 PM – 7 PM)</option>
                    </select>
                </div>
                <button type="button" id="edvoraConfirmCbBtn" style="width:100%; background:#059669; color:#fff; border:none; padding:8px 12px; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer;">
                    📞 Confirm Callback Request &rarr;
                </button>
            `;

            var confirmBtn = quickCard.querySelector('#edvoraConfirmCbBtn');
            var slotSelect = quickCard.querySelector('#edvoraQuickSlot');

            confirmBtn.onclick = function () {
                confirmBtn.innerText = 'Scheduling...';
                confirmBtn.disabled = true;

                fetch(apiBaseUrl + '/v1/callbacks', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        bot_token: botToken,
                        student_name: stored.name,
                        student_phone: stored.phone,
                        student_email: stored.email,
                        preferred_time_slot: slotSelect.value,
                        topic_or_query: 'Requested via Chatbot',
                        department_id: deptId ? parseInt(deptId) : null,
                        conversation_id: currentConversationId,
                        visitor_id: visitorId
                    })
                })
                .then(function (res) { return res.json(); })
                .then(function (res) {
                    if (res.status === 'success') {
                        quickCard.innerHTML = `
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="font-size:22px;">✅</span>
                                <div>
                                    <strong style="color:#166534; font-size:13px;">Callback Confirmed!</strong>
                                    <div style="font-size:11px; color:#15803D; margin-top:2px;">Our admissions counselor will call you on <strong>${stored.masked_phone || stored.phone}</strong> during <strong>${slotSelect.value}</strong>.</div>
                                </div>
                            </div>
                        `;
                    } else {
                        alert(res.message || 'Scheduling failed');
                        confirmBtn.innerText = '📞 Confirm Callback Request →';
                        confirmBtn.disabled = false;
                    }
                })
                .catch(function () {
                    alert('Network error. Please try again.');
                    confirmBtn.innerText = '📞 Confirm Callback Request →';
                    confirmBtn.disabled = false;
                });
            };

            messagesContainer.appendChild(quickCard);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            return;
        }

        // Fresh visitor: Full Form
        var card = document.createElement('div');
        card.className = 'edvora-callback-card';
        card.style.cssText = 'background: #ffffff; border: 1.5px solid #2563EB; border-radius: 12px; padding: 14px; margin-top: 8px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.12); font-size: 12px;';
        card.innerHTML = `
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="font-size:16px;">📞</span>
                    <strong style="color:#1E1B4B; font-size:13px;">Request Counselor Callback</strong>
                </div>
                <span style="background:#EFF6FF; color:#1D4ED8; font-size:10px; font-weight:700; padding:2px 6px; border-radius:8px;">Admissions Desk</span>
            </div>
            <div style="color:#64748B; font-size:11px; margin-bottom:10px; line-height:1.4;">
                Our admissions counselor will call you directly to answer queries regarding courses, fees, scholarships & eligibility.
            </div>
            <form style="display:flex; flex-direction:column; gap:8px;" onsubmit="return false;">
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:3px;">Your Name *</label>
                    <input type="text" placeholder="e.g. Rahul Sharma" required class="edvora-cb-input" style="width:100%; box-sizing:border-box; padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none;" />
                </div>
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:3px;">Email Address *</label>
                    <input type="email" placeholder="e.g. rahul@gmail.com" required class="edvora-cb-input" style="width:100%; box-sizing:border-box; padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none;" />
                </div>
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:3px;">Mobile Phone Number *</label>
                    <input type="tel" placeholder="e.g. 9876543210" required class="edvora-cb-input" style="width:100%; box-sizing:border-box; padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none;" />
                </div>
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:3px;">Preferred Call Time</label>
                    <select class="edvora-cb-input" style="width:100%; box-sizing:border-box; padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none; background:#fff;">
                        <option value="Immediate (ASAP)">⚡ As soon as possible (Within 15 mins)</option>
                        <option value="Morning (9 AM - 12 PM)">🌅 Morning (9:00 AM – 12:00 PM)</option>
                        <option value="Afternoon (12 PM - 4 PM)">☀️ Afternoon (12:00 PM – 4:00 PM)</option>
                        <option value="Evening (4 PM - 7 PM)">🌇 Evening (4:00 PM – 7:00 PM)</option>
                        <option value="Tomorrow Morning">📅 Tomorrow Morning</option>
                    </select>
                </div>
                <button type="button" class="edvora-cb-btn" style="background:#2563EB; color:#fff; border:none; padding:8px 12px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; margin-top:4px;">
                    📞 Book Callback Now &rarr;
                </button>
            </form>
        `;

        var inputs = card.querySelectorAll('.edvora-cb-input');
        var submitBtn = card.querySelector('.edvora-cb-btn');

        submitBtn.onclick = function () {
            var name = inputs[0].value.trim();
            var email = inputs[1].value.trim();
            var phone = inputs[2].value.trim();
            var timeSlot = inputs[3].value;

            if (!name || !email || !phone) {
                alert('Please enter your Full Name, Email Address, and Phone Number.');
                return;
            }

            submitBtn.innerText = 'Registering callback...';
            submitBtn.disabled = true;

            fetch(apiBaseUrl + '/v1/callbacks', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bot_token: botToken,
                    student_name: name,
                    student_email: email,
                    student_phone: phone,
                    preferred_time_slot: timeSlot,
                    department_id: deptId ? parseInt(deptId) : null,
                    conversation_id: currentConversationId,
                    visitor_id: visitorId
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res.status === 'success') {
                    saveStoredLead({ name: name, email: email, phone: phone });
                    card.innerHTML = `
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                            <span style="font-size:20px;">✅</span>
                            <div>
                                <strong style="color:#166534; font-size:13px;">Callback Scheduled!</strong>
                                <div style="font-size:11px; color:#15803D; margin-top:2px;">Our admissions counselor will call you on <strong>${phone}</strong> during <strong>${timeSlot}</strong>.</div>
                            </div>
                        </div>
                    `;
                } else {
                    alert(res.message || 'Failed to schedule callback.');
                    submitBtn.innerText = '📞 Book Callback Now →';
                    submitBtn.disabled = false;
                }
            })
            .catch(function () {
                alert('Network error. Please try again.');
                submitBtn.innerText = '📞 Book Callback Now →';
                submitBtn.disabled = false;
            });
        };

        messagesContainer.appendChild(card);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // --- Interactive Campus Tour Booking Card ---
    function startCampusTourForm() {
        var stored = getStoredLead();

        var card = document.createElement('div');
        card.className = 'edvora-tour-card';
        card.style.cssText = 'background: #ffffff; border: 1.5px solid #7C3AED; border-radius: 12px; padding: 14px; margin-top: 8px; box-shadow: 0 4px 14px rgba(124, 58, 237, 0.12); font-size: 12px;';

        var defaultName = stored ? (stored.name || '') : '';
        var defaultEmail = stored ? (stored.email || '') : '';
        var defaultPhone = stored ? (stored.phone || '') : '';

        card.innerHTML = `
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="font-size:16px;">🏫</span>
                    <strong style="color:#4C1D95; font-size:13px;">Book a Guided Campus Tour</strong>
                </div>
                <span style="background:#F5F3FF; color:#7C3AED; font-size:10px; font-weight:700; padding:2px 6px; border-radius:8px;">Campus Visit</span>
            </div>
            <div style="color:#64748B; font-size:11px; margin-bottom:10px; line-height:1.4;">
                Tour our academic blocks, advanced research labs, sports complex, and hostel amenities with an admissions coordinator.
            </div>
            <div id="edvoraTourSlotContainer" style="margin-bottom:10px; font-size:11px; color:#4C1D95; background:#F5F3FF; border:1px solid #DDD6FE; border-radius:8px; padding:8px;">
                Loading available tour schedules...
            </div>
            <form style="display:flex; flex-direction:column; gap:7px;" onsubmit="return false;">
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:2px;">Full Name *</label>
                    <input type="text" value="${defaultName}" placeholder="e.g. Sneha Patel" required class="edvora-tour-input" style="width:100%; box-sizing:border-box; padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none; background:#fff;" />
                </div>
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:2px;">Email Address *</label>
                    <input type="email" value="${defaultEmail}" placeholder="e.g. sneha@gmail.com" required class="edvora-tour-input" style="width:100%; box-sizing:border-box; padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none; background:#fff;" />
                </div>
                <div>
                    <label style="display:block; font-size:10px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:2px;">Phone Number *</label>
                    <input type="tel" value="${defaultPhone}" placeholder="e.g. 9876543210" required class="edvora-tour-input" style="width:100%; box-sizing:border-box; padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none; background:#fff;" />
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px;">
                    <div>
                        <label style="display:block; font-size:10px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:2px;">Preferred Date</label>
                        <input type="date" class="edvora-tour-input" id="edvoraTourPrefDate" style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:11px; border:1px solid #CBD5E1; border-radius:6px; outline:none; background:#fff;" />
                    </div>
                    <div>
                        <label style="display:block; font-size:10px; font-weight:700; color:#334155; text-transform:uppercase; margin-bottom:2px;">Time Slot</label>
                        <select class="edvora-tour-input" id="edvoraTourPrefTime" style="width:100%; box-sizing:border-box; padding:6px 8px; font-size:11px; border:1px solid #CBD5E1; border-radius:6px; outline:none; background:#fff;">
                            <option value="Morning (10 AM - 12 PM)">🌅 Morning (10 AM)</option>
                            <option value="Afternoon (2 PM - 4 PM)">☀️ Afternoon (2 PM)</option>
                            <option value="Saturday Weekend Tour">📅 Weekend Visit</option>
                        </select>
                    </div>
                </div>
                <button type="button" class="edvora-tour-btn" style="background:#7C3AED; color:#fff; border:none; padding:8px 12px; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer; margin-top:4px;">
                    🏫 Confirm Campus Tour Slot &rarr;
                </button>
            </form>
        `;

        // Fetch active slots for this bot
        var selectedSlotId = null;
        fetch(apiBaseUrl + '/v1/campus-tours/slots?bot_token=' + encodeURIComponent(botToken))
            .then(function(res) { return res.json(); })
            .then(function(res) {
                var slotBox = card.querySelector('#edvoraTourSlotContainer');
                if (slotBox && res.status === 'success' && res.data && res.data.slots && res.data.slots.length > 0) {
                    var html = '<div style="font-weight:700; margin-bottom:4px;">Available Tour Schedules:</div><div style="display:flex; flex-wrap:wrap; gap:5px;">';
                    res.data.slots.slice(0, 4).forEach(function(s) {
                        var progTag = (parseInt(s.is_general, 10) === 1 || !s.programs || s.programs.length === 0) ? 'General' : (s.programs[0].code || 'Specialized');
                        html += '<button type="button" class="edvora-slot-chip" data-slot-id="' + s.id + '" data-date="' + s.tour_date + '" data-time="' + s.start_time + '" style="background:#fff; border:1px solid #C4B5FD; color:#6D28D9; border-radius:6px; padding:4px 8px; font-size:10.5px; cursor:pointer; font-weight:600; text-align:left;">' +
                                '📅 ' + s.tour_date + ' (' + s.start_time.substring(0, 5) + ')' +
                                '<div style="font-size:9.5px; opacity:0.85;">' + progTag + ' • ' + (s.campus_name || 'Campus') + '</div>' +
                                '</button>';
                    });
                    html += '</div>';
                    slotBox.innerHTML = html;

                    var chips = slotBox.querySelectorAll('.edvora-slot-chip');
                    chips.forEach(function(btn) {
                        btn.onclick = function() {
                            chips.forEach(function(b) { b.style.background = '#fff'; b.style.color = '#6D28D9'; b.style.borderColor = '#C4B5FD'; });
                            btn.style.background = '#7C3AED';
                            btn.style.color = '#fff';
                            btn.style.borderColor = '#7C3AED';
                            selectedSlotId = parseInt(btn.getAttribute('data-slot-id'), 10);
                            var dateInput = card.querySelector('#edvoraTourPrefDate');
                            if (dateInput) dateInput.value = btn.getAttribute('data-date');
                        };
                    });
                } else if (slotBox) {
                    slotBox.innerHTML = '✨ <strong>Flexible Visiting Hours:</strong> Pick any preferred date below for a guided walk.';
                }
            })
            .catch(function() {
                var slotBox = card.querySelector('#edvoraTourSlotContainer');
                if (slotBox) slotBox.innerHTML = '✨ <strong>Flexible Visiting Hours:</strong> Pick any preferred date below.';
            });

        var inputs = card.querySelectorAll('.edvora-tour-input');
        var submitBtn = card.querySelector('.edvora-tour-btn');

        submitBtn.onclick = function () {
            var name = inputs[0].value.trim();
            var email = inputs[1].value.trim();
            var phone = inputs[2].value.trim();
            var prefDate = inputs[3].value;
            var prefTime = inputs[4].value;

            if (!name || !email || !phone) {
                alert('Please enter your Full Name, Email Address, and Phone Number.');
                return;
            }

            submitBtn.innerText = 'Booking Tour...';
            submitBtn.disabled = true;

            fetch(apiBaseUrl + '/v1/campus-tours', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bot_token: botToken,
                    name: name,
                    email: email,
                    phone: phone,
                    slot_id: selectedSlotId,
                    preferred_date: prefDate || null,
                    preferred_time: prefTime,
                    department_id: deptId ? parseInt(deptId) : null,
                    conversation_id: currentConversationId,
                    visitor_id: visitorId
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res.status === 'success') {
                    saveStoredLead({ name: name, email: email, phone: phone });
                    card.innerHTML = `
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-size:22px;">🎉</span>
                            <div>
                                <strong style="color:#5B21B6; font-size:13px;">Campus Tour Scheduled!</strong>
                                <div style="font-size:11px; color:#6D28D9; margin-top:2px;">
                                    We look forward to hosting you. A confirmation and directions guide have been dispatched to <strong>${email}</strong>.
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    alert(res.message || 'Failed to book campus tour.');
                    submitBtn.innerText = '🏫 Confirm Campus Tour Slot →';
                    submitBtn.disabled = false;
                }
            })
            .catch(function () {
                alert('Network error. Please try again.');
                submitBtn.innerText = '🏫 Confirm Campus Tour Slot →';
                submitBtn.disabled = false;
            });
        };

        messagesContainer.appendChild(card);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // --- Interactive Scholarship Evaluation Stepper ---
    function startScholarshipEvaluation() {
        var card = document.createElement('div');
        card.className = 'edvora-scholarship-card';
        card.style.cssText = 'background: #ffffff; border: 1.5px solid #6366F1; border-radius: 12px; padding: 12px; margin-top: 8px; box-shadow: 0 4px 14px rgba(99, 102, 241, 0.12); font-size: 12px;';
        card.innerHTML = `
            <div style="display:flex; align-items:center; gap:6px; margin-bottom:6px;">
                <span style="font-size:16px;">🎓</span>
                <strong style="color:#1E1B4B; font-size:13px;">Scholarship & Fee Waiver Check</strong>
            </div>
            <div style="color:#64748B; font-size:11px; margin-bottom:10px;">Loading eligible courses...</div>
        `;
        messagesContainer.appendChild(card);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;

        var url = apiBaseUrl + '/v1/widget/scholarship/courses/' + botToken + (deptId ? ('?dept_id=' + encodeURIComponent(deptId)) : '');
        fetch(url)
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res.status !== 'success' || !res.data || !res.data.scholarships_enabled || !res.data.courses || res.data.courses.length === 0) {
                    card.innerHTML = `
                        <div style="display:flex; align-items:center; gap:6px; margin-bottom:6px;">
                            <span style="font-size:16px;">ℹ️</span>
                            <strong style="color:#1E1B4B; font-size:13px;">Admissions & Financial Aid</strong>
                        </div>
                        <div style="color:#475569; font-size:12px; line-height:1.4; margin-bottom:8px;">
                            Direct merit waiver evaluation is currently not open, but you can request complete fee details and 0% EMI loan options.
                        </div>
                        <button type="button" class="edvora-lead-btn" style="width:100%;" id="edvoraReqFeesBtn">Request Fee Structure & Loan Desk &rarr;</button>
                    `;
                    var btn = card.querySelector('#edvoraReqFeesBtn');
                    if (btn) {
                        btn.onclick = function () {
                            renderLeadBanner({
                                type: 'fee_breakdown',
                                headline: 'Course Fee & Financial Aid Guide',
                                description: 'Enter your details to receive full course fee schedule and education loan assistance.'
                            });
                        };
                    }
                    return;
                }

                var courses = res.data.courses;
                var boosters = res.data.boosters || [];

                renderScholarshipStep1(card, courses, boosters);
            })
            .catch(function () {
                card.innerHTML = '<div style="color:#EF4444; font-size:12px;">Failed to load scholarship schemes. Please try again.</div>';
            });
    }

    function renderScholarshipStep1(card, courses, boosters) {
        var optionsHtml = courses.map(function (c) {
            var badge = c.has_scholarship ? ' (Merit Scholarships Available)' : ' (Fixed Fee / 0% EMI)';
            return `<option value="${c.id}">${c.course_name} [${c.degree_level.toUpperCase()}]${badge}</option>`;
        }).join('');

        card.innerHTML = `
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="font-size:16px;">🎓</span>
                    <strong style="color:#1E1B4B; font-size:13px;">Scholarship & Waiver Calculator</strong>
                </div>
                <span style="background:#EEF2FF; color:#4F46E5; font-size:10px; font-weight:700; padding:2px 6px; border-radius:10px;">Step 1 of 2</span>
            </div>
            <div style="color:#64748B; font-size:11px; margin-bottom:10px;">Select your program and enter your academic score:</div>
            
            <div style="display:flex; flex-direction:column; gap:8px;">
                <div>
                    <label style="font-size:11px; font-weight:600; color:#334155; display:block; margin-bottom:3px;">Select Program *</label>
                    <select id="edvoraSchCourse" style="width:100%; padding:7px 10px; border:1px solid #CBD5E1; border-radius:6px; font-size:12px; outline:none; background:#fff;">
                        ${optionsHtml}
                    </select>
                </div>

                <div>
                    <label id="edvoraScoreLabel" style="font-size:11px; font-weight:600; color:#334155; display:block; margin-bottom:3px;">Enter Class 12th / Qualifying Score (%) *</label>
                    <input type="number" id="edvoraSchScore" placeholder="e.g. 88.5" min="0" max="100" step="0.1" style="width:100%; padding:7px 10px; border:1px solid #CBD5E1; border-radius:6px; font-size:12px; outline:none;" />
                </div>

                <div>
                    <label style="font-size:11px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Special Categories / Quotas (Optional):</label>
                    <div style="display:flex; flex-direction:column; gap:4px;" id="edvoraBoostersList">
                        ${boosters.map(function(b) {
                            return `<label style="display:flex; align-items:center; gap:6px; font-size:11px; color:#475569; cursor:pointer;">
                                <input type="checkbox" value="${b.id}" class="edvora-booster-chk" />
                                <span>${b.label} (+${b.pct}% extra)</span>
                            </label>`;
                        }).join('')}
                    </div>
                </div>

                <button type="button" id="edvoraCalcBtn" class="edvora-lead-btn" style="background:#4F46E5; padding:8px; border-radius:6px; font-weight:600; cursor:pointer; margin-top:4px;">
                    Evaluate My Scholarship &rarr;
                </button>
            </div>
        `;

        var courseSelect = card.querySelector('#edvoraSchCourse');
        var scoreInput = card.querySelector('#edvoraSchScore');
        var scoreLabel = card.querySelector('#edvoraScoreLabel');
        var calcBtn = card.querySelector('#edvoraCalcBtn');

        function updateMetricLabel() {
            var selectedId = parseInt(courseSelect.value);
            var selectedCourse = courses.find(function(c) { return c.id === selectedId; });
            if (selectedCourse) {
                if (selectedCourse.evaluation_metric === 'entrance_exam' && selectedCourse.exam_name) {
                    scoreLabel.innerText = 'Enter ' + selectedCourse.exam_name + ' Percentile / Score *';
                    scoreInput.placeholder = 'e.g. 92.5';
                } else if (selectedCourse.evaluation_metric === 'graduation_cgpa') {
                    scoreLabel.innerText = 'Enter Graduation CGPA (Scale of 10) *';
                    scoreInput.placeholder = 'e.g. 8.4';
                } else if (selectedCourse.evaluation_metric === 'merit_rank') {
                    scoreLabel.innerText = 'Enter State / National Merit Rank *';
                    scoreInput.placeholder = 'e.g. 1420';
                } else {
                    scoreLabel.innerText = 'Enter Class 12th / Qualifying Score (%) *';
                    scoreInput.placeholder = 'e.g. 88.5';
                }
            }
        }

        courseSelect.onchange = updateMetricLabel;
        updateMetricLabel();

        calcBtn.onclick = function () {
            var courseId = parseInt(courseSelect.value);
            var scoreVal = parseFloat(scoreInput.value);

            if (isNaN(scoreVal) || scoreVal < 0) {
                alert('Please enter your valid academic score or percentage.');
                return;
            }

            var selectedBoosters = [];
            card.querySelectorAll('.edvora-booster-chk:checked').forEach(function(chk) {
                selectedBoosters.push(chk.value);
            });

            calcBtn.innerText = 'Calculating...';
            calcBtn.disabled = true;

            fetch(apiBaseUrl + '/v1/widget/scholarship/evaluate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bot_token: botToken,
                    course_id: courseId,
                    score: scoreVal,
                    boosters: selectedBoosters
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res.status === 'success' && res.data) {
                    renderScholarshipStep2(card, res.data, courseId, selectedBoosters);
                } else {
                    alert(res.message || 'Evaluation failed. Please try again.');
                    calcBtn.innerText = 'Evaluate My Scholarship →';
                    calcBtn.disabled = false;
                }
            })
            .catch(function () {
                alert('Network error. Please try again.');
                calcBtn.innerText = 'Evaluate My Scholarship →';
                calcBtn.disabled = false;
            });
        };
    }

    function renderScholarshipStep2(card, evalData, courseId, selectedBoosters) {
        var isScholarshipAvailable = evalData.has_scholarship;
        var isQualified = evalData.is_qualified;

        var resultBannerHtml = '';
        if (isScholarshipAvailable && isQualified) {
            var savingsStr = evalData.estimated_savings ? `<div style="font-size:12px; font-weight:700; color:#15803D; margin-top:2px;">💰 Estimated Tuition Savings: ${evalData.currency} ${evalData.estimated_savings.toLocaleString()} / year</div>` : '';
            var boosterStr = (evalData.active_boosters && evalData.active_boosters.length > 0) ? `<div style="font-size:10px; color:#166534; margin-top:2px;">Includes: ${evalData.active_boosters.join(', ')} booster</div>` : '';
            
            resultBannerHtml = `
                <div style="background:#F0FDF4; border:1.5px solid #86EFAC; border-radius:8px; padding:10px; margin-bottom:10px;">
                    <div style="font-size:14px; font-weight:800; color:#166534;">🎉 ${evalData.total_waiver_pct}% Tuition Waiver Qualified!</div>
                    <div style="font-size:11px; color:#15803D; margin-top:2px;">${evalData.course_name} • Score: ${evalData.score_entered}</div>
                    ${savingsStr}
                    ${boosterStr}
                </div>
            `;
        } else if (isScholarshipAvailable && !isQualified) {
            resultBannerHtml = `
                <div style="background:#FEF3C7; border:1.5px solid #FCD34D; border-radius:8px; padding:10px; margin-bottom:10px;">
                    <div style="font-size:13px; font-weight:700; color:#92400E;">Standard Merit Bracket</div>
                    <div style="font-size:11px; color:#B45309; margin-top:2px;">You are eligible for standard admission in <strong>${evalData.course_name}</strong> along with 0% EMI and education loan assistance.</div>
                </div>
            `;
        } else {
            resultBannerHtml = `
                <div style="background:#F1F5F9; border:1.5px solid #CBD5E1; border-radius:8px; padding:10px; margin-bottom:10px;">
                    <div style="font-size:13px; font-weight:700; color:#1E293B;">Fixed Fee Program Structure</div>
                    <div style="font-size:11px; color:#475569; margin-top:2px;">${evalData.reason || 'This program operates on a standard subsidized fee structure.'}</div>
                    <div style="font-size:11px; color:#0284C7; font-weight:600; margin-top:4px;">✓ 0% Interest EMI & Education Loan Desk available</div>
                </div>
            `;
        }

        var formHeadline = isScholarshipAvailable && isQualified 
            ? "Lock in this scholarship tier & get your Official Assessment Certificate sent to:"
            : "Get the complete Official Fee Breakdown & Education Loan Assistance sent to:";

        card.innerHTML = `
            ${resultBannerHtml}
            <div style="font-size:11px; font-weight:600; color:#334155; margin-bottom:8px;">${formHeadline}</div>
            
            <form style="display:flex; flex-direction:column; gap:6px;" onsubmit="return false;" id="edvoraUnlockForm">
                <input type="text" id="edvoraLeadName" placeholder="Student Full Name *" required style="padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none;" />
                <input type="tel" id="edvoraLeadPhone" placeholder="WhatsApp / Mobile Number *" required style="padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none;" />
                <input type="email" id="edvoraLeadEmail" placeholder="Email Address *" required style="padding:7px 10px; font-size:12px; border:1px solid #CBD5E1; border-radius:6px; outline:none;" />
                <button type="button" id="edvoraUnlockBtn" class="edvora-lead-btn" style="background:#16A34A; padding:8px; border-radius:6px; font-weight:700; cursor:pointer; margin-top:4px;">
                    ${isScholarshipAvailable && isQualified ? 'Claim Official Scholarship Report →' : 'Send Fee & Loan Breakdown →'}
                </button>
            </form>
        `;

        var unlockBtn = card.querySelector('#edvoraUnlockBtn');
        var nameInput = card.querySelector('#edvoraLeadName');
        var phoneInput = card.querySelector('#edvoraLeadPhone');
        var emailInput = card.querySelector('#edvoraLeadEmail');

        unlockBtn.onclick = function () {
            var name = nameInput.value.trim();
            var phone = phoneInput.value.trim();
            var email = emailInput.value.trim();

            if (!name || !phone || !email) {
                alert('Please enter your name, phone number, and email address.');
                return;
            }

            unlockBtn.innerText = 'Claiming & Submitting...';
            unlockBtn.disabled = true;

            var tierStr = isScholarshipAvailable && isQualified ? (evalData.total_waiver_pct + '% Merit Waiver') : 'Standard Admission';
            var scoreStr = evalData.score_entered ? (evalData.score_entered + (evalData.metric_type === 'graduation_cgpa' ? ' CGPA' : (evalData.metric_type === 'merit_rank' ? ' Rank' : '%'))) : '';

            fetch(apiBaseUrl + '/v1/leads', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    bot_token: botToken,
                    name: name,
                    email: email,
                    phone: phone,
                    program_interest: evalData.course_name,
                    department_id: deptId || null,
                    conversation_id: currentConversationId,
                    visitor_id: visitorId,
                    lead_type: 'scholarship_eval',
                    academic_score: scoreStr,
                    scholarship_tier: tierStr,
                    estimated_waiver_amount: evalData.estimated_savings || null,
                    evaluation_payload: {
                        course_id: courseId,
                        course_name: evalData.course_name,
                        score: evalData.score_entered,
                        total_waiver_pct: evalData.total_waiver_pct || 0,
                        boosters: selectedBoosters
                    }
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (res) {
                if (res.status === 'success') {
                    var refId = res.data && res.data.id ? ('#SCH-' + res.data.id) : ('#SCH-' + Math.floor(100000 + Math.random() * 900000));
                    card.innerHTML = `
                        <div style="text-align:center; padding:8px 4px;">
                            <div style="font-size:24px; margin-bottom:4px;">🎉</div>
                            <div style="font-size:14px; font-weight:800; color:#166534; margin-bottom:4px;">Assessment Report Confirmed!</div>
                            <div style="font-size:11px; color:#15803D; margin-bottom:6px;">Your reference number is <strong>${refId}</strong>.</div>
                            <div style="font-size:11px; color:#475569; line-height:1.4;">
                                We have dispatched the official assessment details to <strong>${phone}</strong> and <strong>${email}</strong>. An admissions counselor has been assigned to assist you.
                            </div>
                        </div>
                    `;
                    appendMessage('assistant', 'I have logged your scholarship evaluation for ' + evalData.course_name + ' (Ref ' + refId + '). An admissions counselor will reach out to you with fee details.');
                } else {
                    alert(res.message || 'Submission failed');
                    unlockBtn.innerText = 'Claim Official Scholarship Report →';
                    unlockBtn.disabled = false;
                }
            })
            .catch(function () {
                alert('Network error. Please try again.');
                unlockBtn.innerText = 'Claim Official Scholarship Report →';
                unlockBtn.disabled = false;
            });
        };
    }



    sendBtn.onclick = sendMessage;
    inputField.onkeypress = function (e) {
        if (e.key === 'Enter') sendMessage();
    };
})();
