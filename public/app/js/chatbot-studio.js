// ═══════════════════════════════════════════════════════════════════
// CHATBOT-STUDIO.JS - Widget builder, embed code, test chat, widget customizer
// BUG AREAS:
//   Studio subtab switch  -> switchStudioSubtab()
//   Embed code generator  -> populateEmbedCode() / populateEmbedTargetSelector()
//   Standalone test chat  -> openStandaloneTestChat() / loadTestChatStudio()
//   Test chat scope       -> selectTestBotScope() / updateTestChatCanvas()
//   Widget style builder  -> loadChatbotStudio() / renderStudioPreview()
//   Widget templates      -> selectWidgetTemplate() / setStudioThemeMode()
//   Quick chips           -> updateStudioChips() / wcSyncPreviewChips()
//   Embed script copy     -> copyEmbedScript() / fallbackCopyText()
//   Save widget config    -> saveChatbotWidgetConfig()
//   Widget customizer     -> loadWidgetCustomization() / applyWcToPreview()
//   WC controls           -> wcPopulateControls() / onWcColor() / onWcSlider()
//   WC avatar/logo        -> onWcAvatarUpload() / onWcLogoUpload() / removeWcLogo()
//   WC preview chat       -> wcSendMessage() / wcPreviewClickChip()
//   WC scope/dept         -> onWcScopeChange() / onWcDeptChange()
// LOADED BY: index.html via <script src="js/chatbot-studio.js">
// ═══════════════════════════════════════════════════════════════════
        function switchStudioSubtab(tabName, saveStorage = true) {
            if (tabName !== 'embed') tabName = 'widget';

            const linkWidget = document.getElementById('subtabLinkWidget');
            const linkEmbed = document.getElementById('subtabLinkEmbed');
            const viewWidget = document.getElementById('subtabViewWidget');
            const viewEmbed = document.getElementById('subtabViewEmbed');

            if (tabName === 'embed') {
                if (linkWidget) linkWidget.classList.remove('active');
                if (linkEmbed) linkEmbed.classList.add('active');
                if (viewWidget) viewWidget.style.display = 'none';
                if (viewEmbed) viewEmbed.style.display = 'block';
                if (typeof wcState !== 'undefined' && wcState.scope === 'dept' && wcState.dept_id) {
                    currentEmbedTarget = String(wcState.dept_id);
                }
                if (typeof populateEmbedCode === 'function') {
                    populateEmbedCode();
                }
            } else {
                if (linkWidget) linkWidget.classList.add('active');
                if (linkEmbed) linkEmbed.classList.remove('active');
                if (viewWidget) viewWidget.style.display = 'block';
                if (viewEmbed) viewEmbed.style.display = 'none';
            }

            // Adjust header button visibility
            const saveBtn = document.getElementById('saveWidgetConfigBtn') || document.getElementById('wcSaveBtn');
            if (saveBtn) {
                saveBtn.style.display = (tabName === 'embed') ? 'none' : 'inline-flex';
            }

            if (saveStorage) {
                localStorage.setItem('edvora_studio_subtab', tabName);
            }

            if (tabName === 'widget') {
                if (typeof loadWidgetCustomization === 'function') {
                    loadWidgetCustomization();
                }
            }
        }

        let currentEmbedTarget = 'org'; // 'org' or department id as number

        async function populateEmbedTargetSelector() {
            const sel = document.getElementById('embedTargetSelect');
            if (!sel) return;
            sel.innerHTML = '<option value="org">🏛️ Main Organization Widget</option>';
            currentEmbedTarget = 'org';
            sel.value = 'org';
        }

        async function onEmbedTargetChange(val) {
            currentEmbedTarget = val;
            const badge = document.getElementById('embedDeptStatusBadge');
            if (badge) {
                if (val === 'org') {
                    badge.innerHTML = '🌐 Organization-wide general concierge widget';
                    badge.style.color = '#047857';
                } else {
                    badge.innerHTML = '🎯 Dedicated Department Assistant • Scoped directly to department';
                    badge.style.color = '#1D4ED8';
                }
            }
            await populateEmbedCode();
        }

        async function populateEmbedCode() {
            try {
                await populateEmbedTargetSelector();

                let tokenVal = (currentWidgetBot && currentWidgetBot.botToken) || window.currentBotToken || (window.currentOrg && window.currentOrg.bot_token);
                if (!tokenVal && token) {
                    const res = await fetch('/v1/chatbots', { headers: { 'Authorization': 'Bearer ' + token } });
                    const data = await res.json();
                    if (data.status === 'success' && data.data && data.data.length > 0) {
                        const bot = data.data[0];
                        if (currentWidgetBot) {
                            currentWidgetBot.id = bot.id;
                            currentWidgetBot.botToken = bot.bot_token;
                        }
                        tokenVal = bot.bot_token;
                    }
                }
                const finalToken = tokenVal || (currentWidgetBot && currentWidgetBot.botToken) || '0f43719940f8e505c121f2d61479aaaa';
                
                let snippet = '';
                if (currentEmbedTarget && currentEmbedTarget !== 'org') {
                    snippet = '<script src="https://edvora.chat/widget.js" data-bot-token="' + finalToken + '" data-dept-id="' + currentEmbedTarget + '" async><' + '/script>';
                } else {
                    snippet = '<script src="https://edvora.chat/widget.js" data-bot-token="' + finalToken + '" async><' + '/script>';
                }
                
                const box = document.getElementById('embedCodeBox');
                if (box) {
                    box.innerText = snippet;
                }
                const tokenDisplay = document.getElementById('embedBotTokenDisplay');
                if (tokenDisplay) {
                    tokenDisplay.innerHTML = finalToken + (currentEmbedTarget !== 'org' ? ' &bull; Dept ID: <strong>' + currentEmbedTarget + '</strong>' : '');
                }
            } catch(err) {
                console.error('Error populating embed code:', err);
            }
        }

        async function openStandaloneTestChat() {
            try {
                let tokenVal = (currentWidgetBot && currentWidgetBot.botToken) || window.currentBotToken || (window.currentOrg && window.currentOrg.bot_token);
                if (!tokenVal && token) {
                    const res = await fetch('/v1/chatbots', { headers: { 'Authorization': 'Bearer ' + token } });
                    const data = await res.json();
                    if (data.status === 'success' && data.data && data.data.length > 0) {
                        const bot = data.data[0];
                        if (currentWidgetBot) {
                            currentWidgetBot.id = bot.id;
                            currentWidgetBot.botToken = bot.bot_token;
                        }
                        tokenVal = bot.bot_token;
                    }
                }
                
                let targetDeptId = null;
                // If on widget tab with department scope selected, use wcState.dept_id
                const subtabEmbed = document.getElementById('subtabViewEmbed');
                const isEmbedTabVisible = subtabEmbed && subtabEmbed.style.display !== 'none';
                if (isEmbedTabVisible && currentEmbedTarget !== 'org') {
                    targetDeptId = currentEmbedTarget;
                } else if (typeof wcState !== 'undefined' && wcState.scope === 'dept' && wcState.dept_id) {
                    targetDeptId = wcState.dept_id;
                }

                let url = '/test_chat.html';
                if (tokenVal) {
                    url += '?token=' + encodeURIComponent(tokenVal);
                    if (targetDeptId) {
                        url += '&dept_id=' + encodeURIComponent(targetDeptId);
                    }
                }
                window.open(url, '_blank');
            } catch (err) {
                console.error('Error opening test chat:', err);
                window.open('/test_chat.html', '_blank');
            }
        }

        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        //  TEST CHAT STUDIO CONTROLLER
        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

        async function loadTestChatStudio() {
            if (!currentWidgetBot || !currentWidgetBot.botToken) {
                try {
                    await loadChatbotStudio();
                } catch(e) {}
            }

            const botToken = currentWidgetBot.botToken || '0f43719940f8e505c121f2d61479aaaa';
            const grid = document.getElementById('testBotCardsGrid');
            if (!grid) return;

            const authToken = localStorage.getItem('edvora_token') || token;

            // Fetch departments with dedicated widget enabled
            try {
                const res = await fetch('/v1/departments', {
                    headers: { 'Authorization': 'Bearer ' + authToken }
                });
                const data = await res.json();
                testChatState.departments = (data.status === 'success' && Array.isArray(data.data)) ? data.data.filter(d => (d.enable_dedicated_widget == 1 || d.enable_dedicated_widget === true || d.enable_dedicated_widget === '1')) : [];
            } catch(e) {
                testChatState.departments = [];
            }

            // Render all cards: Org-Wide + Each department
            let cardsHtml = '';
            
            // 1. Org-Wide Card
            const isOrgActive = (testChatState.scope === 'org');
            const orgName = currentWidgetBot.name || window.currentOrgName || 'General Admissions';
            cardsHtml += `
                <div class="test-bot-card ${isOrgActive ? 'active' : ''}" onclick="selectTestBotScope('org', null)">
                    <div class="test-bot-card-icon">ðŸ›ï¸</div>
                    <div class="test-bot-card-info">
                        <div class="test-bot-card-title">Org-Wide (General Bot)</div>
                        <div class="test-bot-card-sub">${orgName}</div>
                    </div>
                    <div class="test-bot-card-radio"></div>
                </div>
            `;

            // 2. Department Cards
            testChatState.departments.forEach(dept => {
                const isDeptActive = (testChatState.scope === 'dept' && testChatState.deptId == dept.id);
                cardsHtml += `
                    <div class="test-bot-card ${isDeptActive ? 'active' : ''}" onclick="selectTestBotScope('dept', ${dept.id})">
                        <div class="test-bot-card-icon">${dept.icon || 'ðŸ¢'}</div>
                        <div class="test-bot-card-info">
                            <div class="test-bot-card-title">${dept.name}</div>
                            <div class="test-bot-card-sub">Department Scope</div>
                        </div>
                        <div class="test-bot-card-radio"></div>
                    </div>
                `;
            });

            grid.innerHTML = cardsHtml;

            // Mount the test canvas
            updateTestChatCanvas();
        }

        function selectTestBotScope(scope, deptId = null) {
            testChatState.scope = scope;
            testChatState.deptId = deptId;

            // Update active card highlighting
            const cards = document.querySelectorAll('.test-bot-card');
            cards.forEach(card => card.classList.remove('active'));

            if (scope === 'org') {
                if (cards[0]) cards[0].classList.add('active');
            } else {
                cards.forEach(card => {
                    const onclickAttr = card.getAttribute('onclick') || '';
                    if (onclickAttr.includes(`'dept', ${deptId}`) || onclickAttr.includes(`"dept", ${deptId}`)) {
                        card.classList.add('active');
                    }
                });
            }

            // Brief loading flash for split second
            const loader = document.getElementById('testCanvasLoading');
            if (loader) {
                loader.style.display = 'flex';
                setTimeout(() => {
                    loader.style.display = 'none';
                }, 280);
            }

            updateTestChatCanvas();
        }

        function updateTestChatCanvas() {
            const botToken = currentWidgetBot.botToken || '0f43719940f8e505c121f2d61479aaaa';
            let testUrl = `${window.location.origin}/test/${botToken}`;
            if (testChatState.scope === 'dept' && testChatState.deptId) {
                testUrl += `?dept=${testChatState.deptId}`;
            }

            const input = document.getElementById('testShareUrlInput');
            if (input) input.value = testUrl;

            const openBtn = document.getElementById('testOpenNewTabBtn');
            if (openBtn) openBtn.href = testUrl;

            const iframe = document.getElementById('testChatIframe');
            if (iframe) {
                const sep = testUrl.includes('?') ? '&' : '?';
                const iframeUrl = testUrl + sep + '_t=' + Date.now();
                iframe.src = iframeUrl;
            }
        }

        function copyTestShareUrl() {
            const input = document.getElementById('testShareUrlInput');
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            try {
                navigator.clipboard.writeText(input.value);
            } catch(e) {
                document.execCommand('copy');
            }

            const text = document.getElementById('testCopyBtnText');
            const icon = document.getElementById('testCopyBtnIcon');
            if (text) text.innerText = 'Copied!';
            if (icon) icon.innerText = 'âœ“';
            showToast('Shareable test link copied to clipboard! âœ“', 'success');

            setTimeout(() => {
                if (text) text.innerText = 'Copy Link';
                if (icon) icon.innerText = 'ðŸ“‹';
            }, 2200);
        }

        function resetTestChatSession() {
            const loader = document.getElementById('testCanvasLoading');
            if (loader) {
                loader.style.display = 'flex';
                setTimeout(() => {
                    loader.style.display = 'none';
                }, 300);
            }
            updateTestChatCanvas();
            showToast('Test session reset successfully. Fresh conversation started.', 'info');
        }


        async function loadChatbotStudio() {
            if (!token) return;
            try {
                const res = await fetch('/v1/chatbots', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await res.json();
                if (data.status === 'success' && data.data && data.data.length > 0) {
                    const bot = data.data[0];
                    currentWidgetBot.id = bot.id;
                    currentWidgetBot.botToken = bot.bot_token || '0f43719940f8e505c121f2d61479aaaa';
                    currentWidgetBot.name = bot.name || 'LeadBot';
                    currentWidgetBot.welcome_message = bot.welcome_message || 'Hello! ðŸ‘‹ Welcome to our assistant console. How can I help you today?';
                    currentWidgetBot.primary_color = bot.primary_color || '#063D3B';
                    currentWidgetBot.secondary_color = bot.secondary_color || '#059669';
                    currentWidgetBot.widget_style = bot.widget_style || 'glassmorphism';
                    currentWidgetBot.theme_mode = bot.theme_mode || 'light';
                    currentWidgetBot.header_subtitle = bot.header_subtitle || 'Online Now';
                    currentWidgetBot.launcher_icon = bot.launcher_icon || 'chat';
                    currentWidgetBot.launcher_text = bot.launcher_text || 'Ask AI';
                    currentWidgetBot.border_radius = bot.border_radius || 'curved';
                    currentWidgetBot.avatar_icon = bot.avatar_icon || 'ðŸ¤–';
                    
                    if (bot.quick_chips) {
                        try {
                            const parsed = typeof bot.quick_chips === 'string' ? JSON.parse(bot.quick_chips) : bot.quick_chips;
                            if (Array.isArray(parsed)) {
                                currentWidgetBot.quick_chips = parsed.map(c => (typeof c === 'object' && c !== null) ? (c.label || c.message || '') : String(c)).filter(Boolean);
                            } else {
                                currentWidgetBot.quick_chips = String(bot.quick_chips).split(',').map(s => s.trim()).filter(Boolean);
                            }
                        } catch(e) {
                            currentWidgetBot.quick_chips = (bot.quick_chips || '').split(',').map(s => s.trim()).filter(Boolean);
                        }
                    } else {
                        currentWidgetBot.quick_chips = [];
                    }

                    populateStudioInputs();
                    renderStudioPreview();
                }
            } catch (err) {
                console.error('Error loading chatbot config:', err);
            }
        }

        function populateStudioInputs() {
            const nameEl = document.getElementById('botNameInput');
            if (nameEl) nameEl.value = currentWidgetBot.name || 'LeadBot';

            const subEl = document.getElementById('botSubtitleInput');
            if (subEl) subEl.value = currentWidgetBot.header_subtitle || 'Online Now';

            const welEl = document.getElementById('welcomeMsgInput');
            if (welEl) welEl.value = currentWidgetBot.welcome_message || ("Hi there! 👋 Welcome to " + (window.currentOrgName || "our institution") + ". Ask me anything about degree programs, admissions, eligibility, fees, or campus life!");

            const pIn = document.getElementById('primaryColorInput');
            if (pIn) pIn.value = currentWidgetBot.primary_color || '#063D3B';

            const pHex = document.getElementById('primaryColorHex');
            if (pHex) pHex.value = currentWidgetBot.primary_color || '#063D3B';

            const avEl = document.getElementById('avatarIconSelect');
            if (avEl) avEl.value = currentWidgetBot.avatar_icon || 'ðŸ¤–';

            const laIconEl = document.getElementById('launcherIconSelect');
            if (laIconEl) laIconEl.value = currentWidgetBot.launcher_icon || 'chat';

            const laTxtEl = document.getElementById('launcherTextInput');
            if (laTxtEl) laTxtEl.value = currentWidgetBot.launcher_text || 'Ask AI';

            const radEl = document.getElementById('borderRadiusSelect');
            if (radEl) radEl.value = currentWidgetBot.border_radius || 'curved';
            
            const chips = Array.isArray(currentWidgetBot.quick_chips) ? currentWidgetBot.quick_chips.join(', ') : currentWidgetBot.quick_chips;
            const chipsInput = document.getElementById('quickChipsInput');
            if (chipsInput) chipsInput.value = chips || '';

            // Select layout template card
            selectWidgetTemplate(currentWidgetBot.widget_style || 'glassmorphism', false);
            // Select theme mode button
            setStudioThemeMode(currentWidgetBot.theme_mode || 'light', false);

            // Highlight active color swatch
            const curHex = (currentWidgetBot.primary_color || '#063D3B').toLowerCase();
            document.querySelectorAll('.swatch-circle').forEach(sw => {
                const swOnclick = sw.getAttribute('onclick') || '';
                if (swOnclick.toLowerCase().includes(curHex)) {
                    sw.classList.add('active');
                } else {
                    sw.classList.remove('active');
                }
            });
        }

        function selectWidgetTemplate(style, updateState = true) {
            if (updateState) currentWidgetBot.widget_style = style;
            
            document.querySelectorAll('.template-mockup-card').forEach(card => {
                const badge = card.querySelector('.template-radio-badge');
                if (card.getAttribute('data-style') === style) {
                    card.classList.add('selected');
                    card.style.border = '2px solid #063D3B';
                    if (badge) {
                        badge.style.background = '#063D3B';
                        badge.style.color = '#C8FF63';
                        badge.style.border = 'none';
                        badge.innerText = 'âœ“';
                    }
                } else {
                    card.classList.remove('selected');
                    card.style.border = '1.5px solid #E2EFEA';
                    if (badge) {
                        badge.style.background = 'transparent';
                        badge.style.border = '1.5px solid #CBDAD4';
                        badge.innerText = '';
                    }
                }
            });

            renderStudioPreview();
        }

        function setStudioThemeMode(mode, updateState = true) {
            if (updateState) currentWidgetBot.theme_mode = mode;

            const lightCard = document.getElementById('modeLightCard');
            const darkCard = document.getElementById('modeDarkCard');

            if (lightCard && darkCard) {
                if (mode === 'light') {
                    lightCard.classList.add('active');
                    lightCard.style.border = '1.5px solid #063D3B';
                    lightCard.style.background = '#E6F7D2';
                    lightCard.style.color = '#063D3B';
                    
                    darkCard.classList.remove('active');
                    darkCard.style.border = '1.5px solid #DCE9E5';
                    darkCard.style.background = '#FFFFFF';
                    darkCard.style.color = '#4F7470';
                } else {
                    darkCard.classList.add('active');
                    darkCard.style.border = '1.5px solid #063D3B';
                    darkCard.style.background = '#E6F7D2';
                    darkCard.style.color = '#063D3B';
                    
                    lightCard.classList.remove('active');
                    lightCard.style.border = '1.5px solid #DCE9E5';
                    lightCard.style.background = '#FFFFFF';
                    lightCard.style.color = '#4F7470';
                }
            }

            renderStudioPreview();
        }

        function selectStudioColor(colorHex, el) {
            currentWidgetBot.primary_color = colorHex;
            document.querySelectorAll('.swatch-circle').forEach(sw => sw.classList.remove('active'));
            if (el) el.classList.add('active');

            const pIn = document.getElementById('primaryColorInput');
            const pHex = document.getElementById('primaryColorHex');
            if (pIn) pIn.value = colorHex;
            if (pHex) pHex.value = colorHex;

            renderStudioPreview();
        }

        function onCustomColorInput(colorHex) {
            currentWidgetBot.primary_color = colorHex;
            const pIn = document.getElementById('primaryColorInput');
            const pHex = document.getElementById('primaryColorHex');
            if (pIn && pIn.value !== colorHex) pIn.value = colorHex;
            if (pHex && pHex.value !== colorHex) pHex.value = colorHex;

            const targetHex = (colorHex || '').toLowerCase();
            document.querySelectorAll('.swatch-circle').forEach(sw => {
                const swOnclick = sw.getAttribute('onclick') || '';
                if (swOnclick.toLowerCase().includes(targetHex)) {
                    sw.classList.add('active');
                } else {
                    sw.classList.remove('active');
                }
            });

            renderStudioPreview();
        }

        function updateStudioText() {
            const nameEl = document.getElementById('botNameInput');
            if (nameEl) currentWidgetBot.name = nameEl.value;

            const subEl = document.getElementById('botSubtitleInput');
            if (subEl) currentWidgetBot.header_subtitle = subEl.value;

            const avEl = document.getElementById('avatarIconSelect');
            if (avEl) currentWidgetBot.avatar_icon = avEl.value;

            const laIconEl = document.getElementById('launcherIconSelect');
            if (laIconEl) currentWidgetBot.launcher_icon = laIconEl.value;

            const laTxtEl = document.getElementById('launcherTextInput');
            if (laTxtEl) currentWidgetBot.launcher_text = laTxtEl.value;

            const welEl = document.getElementById('welcomeMsgInput');
            if (welEl) currentWidgetBot.welcome_message = welEl.value;

            renderStudioPreview();
        }

        function updateStudioRadius() {
            const radEl = document.getElementById('borderRadiusSelect');
            if (radEl) currentWidgetBot.border_radius = radEl.value;
            renderStudioPreview();
        }

        function updateStudioChips() {
            const valEl = document.getElementById('quickChipsInput');
            if (valEl) {
                const arr = valEl.value.split(',').map(s => s.trim()).filter(Boolean);
                currentWidgetBot.quick_chips = arr;
                if (window.wcState && window.wcState.config) {
                    window.wcState.config.quick_chips = valEl.value.trim();
                }
                renderStudioPreview();
                if (typeof applyWcToPreview === 'function' && window.wcState && window.wcState.config) {
                    applyWcToPreview(window.wcState.config);
                }
            }
        }

        function renderStudioPreview() {
            const widgetWin = document.getElementById('previewWidgetWindow');
            if (!widgetWin) return;

            const p = currentWidgetBot.primary_color || '#063D3B';
            const mode = currentWidgetBot.theme_mode || 'light';

            // 1. Update Right Panel Live Chat Widget Preview (Light / Dark mode styling)
            if (mode === 'dark') {
                widgetWin.classList.add('mode-dark');
                widgetWin.style.background = '#062826';
                const stream = document.getElementById('previewMessageStream');
                if (stream) stream.style.background = '#041F1E';
                const welBub = document.getElementById('previewWelcomeBubble');
                if (welBub) {
                    welBub.style.background = 'rgba(255, 255, 255, 0.12)';
                    welBub.style.color = '#FFFFFF';
                }
                const inputBar = widgetWin.querySelector('.live-panel-input-bar');
                if (inputBar) {
                    inputBar.style.background = '#062826';
                    inputBar.style.borderTop = '1px solid rgba(255, 255, 255, 0.1)';
                }
                const input = document.getElementById('previewInput');
                if (input) input.style.color = '#FFFFFF';
            } else {
                widgetWin.classList.remove('mode-dark');
                widgetWin.style.background = '#FFFFFF';
                const stream = document.getElementById('previewMessageStream');
                if (stream) stream.style.background = '#F8FBFA';
                const welBub = document.getElementById('previewWelcomeBubble');
                if (welBub) {
                    welBub.style.background = '#E6F0EC';
                    welBub.style.color = '#063D3B';
                }
                const inputBar = widgetWin.querySelector('.live-panel-input-bar');
                if (inputBar) {
                    inputBar.style.background = '#FFFFFF';
                    inputBar.style.borderTop = '1px solid #E6F0EC';
                }
                const input = document.getElementById('previewInput');
                if (input) input.style.color = '#063D3B';
            }

            // Border radius
            const rad = currentWidgetBot.border_radius || 'curved';
            if (rad === 'sharp') {
                widgetWin.style.borderRadius = '6px';
            } else if (rad === 'pill') {
                widgetWin.style.borderRadius = '24px';
            } else {
                widgetWin.style.borderRadius = '14px';
            }

            const headerEl = document.getElementById('previewHeader');
            if (headerEl) headerEl.style.background = p;

            const botTitle = document.getElementById('previewBotTitle');
            if (botTitle) botTitle.innerText = currentWidgetBot.name || 'LeadBot';

            const botSub = document.getElementById('previewBotSubtitle');
            if (botSub) botSub.innerText = currentWidgetBot.header_subtitle || 'Online Now';

            const avBox = document.getElementById('previewAvatarBox');
            if (avBox) avBox.innerText = currentWidgetBot.avatar_icon || 'ðŸ¤–';

            const welBub = document.getElementById('previewWelcomeBubble');
            if (welBub) welBub.innerText = currentWidgetBot.welcome_message || 'Hello! ðŸ‘‹ Welcome to our assistant console. How can I help you today?';

            // Set user sample bubbles background color
            document.querySelectorAll('.p-bubble-user').forEach(b => {
                b.style.background = p;
                b.style.color = '#FFFFFF';
            });

            // Render Prompt Chips in Preview
            const chipsBox = document.getElementById('previewChipsContainer');
            if (chipsBox) {
                const rawArr = Array.isArray(currentWidgetBot.quick_chips) ? currentWidgetBot.quick_chips : (currentWidgetBot.quick_chips || '').split(',');
                const chipsArr = rawArr.map(s => String(s).trim()).filter(Boolean);
                if (chipsArr.length === 0) {
                    chipsBox.innerHTML = '';
                    chipsBox.style.display = 'none';
                } else {
                    chipsBox.style.display = 'flex';
                    chipsBox.innerHTML = chipsArr.map(chip => `
                        <span class="mini-chip" style="border: 1px solid ${p}; color: ${p}; background: ${mode === 'dark' ? 'rgba(200, 255, 99, 0.12)' : '#E6F7D2'}; padding: 2px 8px; border-radius: 10px; font-size: 9.5px; cursor: pointer; font-weight: 600;" onclick="clickPreviewChip('${chip.replace(/'/g, "\\'")}')">${chip}</span>
                    `).join('');
                }
            }

            // Send button styling
            const sendBtn = document.getElementById('previewSendBtn');
            if (sendBtn) {
                sendBtn.style.background = p;
            }

            // 2. Update Embed Code Box
            const codeBox = document.getElementById('embedCodeBox');
            if (codeBox) {
                const scriptTag = `&lt;script src="https://edvora.chat/widget.js" data-bot-token="${currentWidgetBot.botToken || '0f43719940f8e505c121f2d61479aaaa'}" async&gt;&lt;/script&gt;`;
                codeBox.innerHTML = scriptTag;
            }
        }

        function clickPreviewChip(chipText) {
            const input = document.getElementById('previewInput');
            if (input) {
                input.value = chipText;
                sendPreviewSampleMessage();
            }
        }

        function handlePreviewKeyDown(e) {
            if (e.key === 'Enter') {
                sendPreviewSampleMessage();
            }
        }

        function sendPreviewSampleMessage() {
            const input = document.getElementById('previewInput');
            if (!input) return;
            const msg = input.value.trim();
            if (!msg) return;

            const stream = document.getElementById('previewMessageStream');
            if (!stream) return;
            
            const p = currentWidgetBot.primary_color || '#063D3B';

            // Append User Bubble
            const userMsg = document.createElement('div');
            userMsg.className = 'p-bubble-user';
            userMsg.style.cssText = `max-width: 82%; padding: 8px 12px; line-height: 1.4; align-self: flex-end; background: ${p}; color: #FFFFFF; border-radius: 12px 12px 2px 12px; font-size: 11px; margin-top: 4px;`;
            userMsg.innerText = msg;
            stream.appendChild(userMsg);

            input.value = '';
            stream.scrollTop = stream.scrollHeight;

            // Simulated bot reply after 350ms
            setTimeout(() => {
                const mode = currentWidgetBot.theme_mode || 'light';
                const botMsg = document.createElement('div');
                botMsg.className = 'p-bubble-bot';
                botMsg.style.cssText = `max-width: 85%; padding: 8px 12px; line-height: 1.4; align-self: flex-start; background: ${mode === 'dark' ? 'rgba(255, 255, 255, 0.12)' : '#E6F0EC'}; color: ${mode === 'dark' ? '#FFFFFF' : '#063D3B'}; border-radius: 12px 12px 12px 2px; font-size: 11px; margin-top: 4px;`;
                botMsg.innerText = `Thank you for asking about "${msg}". This is a live interactive demonstration of your customized chatbot! ðŸŽ“`;
                stream.appendChild(botMsg);
                stream.scrollTop = stream.scrollHeight;
            }, 350);
        }

        async function copyEmbedScript() {
            let tokenVal = (currentWidgetBot && currentWidgetBot.botToken) || window.currentBotToken || (window.currentOrg && window.currentOrg.bot_token);
            if (!tokenVal && token) {
                try {
                    const res = await fetch('/v1/chatbots', { headers: { 'Authorization': 'Bearer ' + token } });
                    const data = await res.json();
                    if (data.status === 'success' && data.data && data.data.length > 0) {
                        const bot = data.data[0];
                        if (currentWidgetBot) {
                            currentWidgetBot.id = bot.id;
                            currentWidgetBot.botToken = bot.bot_token;
                        }
                        tokenVal = bot.bot_token;
                    }
                } catch(e) {}
            }
            const finalToken = tokenVal || (currentWidgetBot && currentWidgetBot.botToken) || '0f43719940f8e505c121f2d61479aaaa';
            let snippet = '';
            if (currentEmbedTarget && currentEmbedTarget !== 'org') {
                snippet = '<script src="https://edvora.chat/widget.js" data-bot-token="' + finalToken + '" data-dept-id="' + currentEmbedTarget + '" async><' + '/script>';
            } else {
                snippet = '<script src="https://edvora.chat/widget.js" data-bot-token="' + finalToken + '" async><' + '/script>';
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(snippet).catch(() => {
                    fallbackCopyText(snippet);
                });
            } else {
                fallbackCopyText(snippet);
            }

            const copyBtn = document.getElementById('copyScriptBtn');
            if (copyBtn) {
                const originalHtml = copyBtn.innerHTML;
                copyBtn.innerHTML = '<span>âœ“</span> <span>Copied!</span>';
                copyBtn.style.background = '#E6F7D2';
                copyBtn.style.borderColor = '#C8FF63';
                setTimeout(() => { 
                    copyBtn.innerHTML = originalHtml;
                    copyBtn.style.background = '#FFFFFF';
                    copyBtn.style.borderColor = '#C8FF63';
                }, 2000);
            }
        }

        function fallbackCopyText(text) {
            const tempInput = document.createElement('textarea');
            tempInput.value = text;
            tempInput.style.position = 'fixed';
            tempInput.style.left = '-9999px';
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
        }

        async function saveChatbotWidgetConfig() {
            if (!currentWidgetBot.id) {
                alert('No active chatbot found to update.');
                return;
            }

            const btn = document.getElementById('saveWidgetConfigBtn');
            if (btn) {
                btn.innerText = 'â³ Saving...';
                btn.disabled = true;
            }

            try {
                const payload = {
                    name: currentWidgetBot.name,
                    welcome_message: currentWidgetBot.welcome_message,
                    primary_color: currentWidgetBot.primary_color,
                    secondary_color: currentWidgetBot.secondary_color,
                    widget_style: currentWidgetBot.widget_style,
                    theme_mode: currentWidgetBot.theme_mode,
                    header_subtitle: currentWidgetBot.header_subtitle,
                    launcher_icon: currentWidgetBot.launcher_icon,
                    launcher_text: currentWidgetBot.launcher_text,
                    border_radius: currentWidgetBot.border_radius,
                    avatar_icon: currentWidgetBot.avatar_icon,
                    quick_chips: currentWidgetBot.quick_chips
                };

                const res = await fetch(`/v1/chatbots/${currentWidgetBot.id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    const statusText = document.getElementById('studioSaveStatus');
                    if (statusText) {
                        statusText.style.display = 'inline';
                        setTimeout(() => { statusText.style.display = 'none'; }, 3000);
                    }
                } else {
                    alert(data.message || 'Failed to save widget configuration.');
                }
            } catch (err) {
                console.error('Error saving widget config:', err);
                alert('Error saving chatbot widget design.');
            } finally {
                if (btn) {
                    btn.innerText = 'ðŸ’¾ Save & Deploy Widget Design';
                    btn.disabled = false;
                }
            }
        }


        // NOTE: App initialization (initDashboard) is handled by the boot guard in js/app-core.js
        // It fires on the 'edvora:partials-ready' event after all HTML partials are loaded.
        // BUG AREA: app not booting -> see bottom of js/app-core.js (boot guard)



        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        //  WIDGET CUSTOMIZATION ENGINE
        // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

        const WC_DEFAULTS = {
            window_bg_color: '#ffffff', window_border_color: '#e2e8f0',
            window_border_width: 1, window_border_radius: 16, window_width: 380, window_height: 550, window_shadow: 'soft',
            header_bg: '#063D3B', header_text_color: '#ffffff', header_logo_url: null,
            header_bot_name: 'AI Admissions Assistant', header_subtitle: 'Online Now',
            welcome_message: "Hi there! 👋 Welcome to " + (window.currentOrgName || "our institution") + ". Ask me anything about degree programs, admissions, eligibility, fees, or campus life!",
            avatar_type: 'preset', avatar_preset: 1, avatar_url: null, avatar_location: 'bubbles',
            bot_bubble_bg: '#f1f5f9', bot_bubble_text: '#1e293b', bot_bubble_radius: 14,
            user_bubble_bg: '#063D3B', user_bubble_text: '#ffffff', user_bubble_radius: 14,
            message_area_bg: '#f9fafb', message_font_size: 13,
            input_bg: '#ffffff', input_border_color: '#e2e8f0', input_text_color: '#1e293b',
            input_placeholder: 'Ask me anything...', input_border_radius: 24,
            send_btn_bg: '#063D3B', send_btn_icon_color: '#ffffff',
            launcher_style: 'circle', launcher_bg: '#063D3B', launcher_icon_color: '#ffffff',
            launcher_icon: 'modern_chat', launcher_text: 'Ask AI', launcher_position: 'bottom-right', launcher_size: 60,
            show_branding: true,
            chip_bg: 'transparent', chip_border_color: '#063D3B', chip_text_color: '#063D3B', chip_border_radius: 20,
            theme: 'light',
            show_action_brochure: true,
            show_action_callback: true,
            show_action_tour: true,
            quick_chips: ''
        };

        const WC_SHADOW_MAP = {
            none: 'none',
            soft: '0 8px 24px -4px rgba(0, 0, 0, 0.22), 0 4px 12px -2px rgba(0, 0, 0, 0.14)',
            medium: '0 18px 38px -6px rgba(0, 0, 0, 0.38), 0 8px 18px -4px rgba(0, 0, 0, 0.24)',
            deep: '0 30px 65px -8px rgba(0, 0, 0, 0.58), 0 16px 30px -6px rgba(0, 0, 0, 0.38)'
        };

        const WC_LAUNCHER_ICONS = {
            modern_chat: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block; vertical-align:middle;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
            chat: '&#128172;',
            robot: '&#129302;',
            sparkles: '&#10024;',
            headset: '&#127911;',
            star: '&#11088;'
        };

        let wcState = { scope: 'org', dept_id: null, config: {...WC_DEFAULTS}, botId: null, depts: [], loaded: false };
        window.wcState = wcState;

        async function loadWidgetCustomization() {
            if (!token) return;
            if (!currentWidgetBot || !currentWidgetBot.id) {
                try {
                    const bRes = await fetch('/v1/chatbots', { headers: { 'Authorization': 'Bearer ' + token } });
                    const bData = await bRes.json();
                    if (bData.status === 'success' && bData.data && bData.data.length > 0) {
                        const bot = bData.data[0];
                        currentWidgetBot.id = bot.id;
                        currentWidgetBot.botToken = bot.bot_token || '0f43719940f8e505c121f2d61479aaaa';
                        currentWidgetBot.name = bot.name || window.currentOrgName || 'AI Admissions Assistant';
                    }
                } catch(e) { console.error('Error fetching initial bot for WC:', e); }
            }
            if (!currentWidgetBot || !currentWidgetBot.id) return;
            wcState.botId = currentWidgetBot.id;
            wcState.scope = 'org';
            wcState.dept_id = null;
            wcState.loaded = true;
            
            const params = new URLSearchParams({ bot_id: wcState.botId, scope: 'org' });
            try {
                if (!window.currentOrgProfile || !window.currentOrgName || !window.currentOrgWebsite || !window.currentOrgProfile.website_url) {
                    try {
                        const oRes = await fetch('/v1/organization/profile', { headers: { 'Authorization': 'Bearer ' + token } });
                        const oData = await oRes.json();
                        if (oData.status === 'success' && oData.data) {
                            window.currentOrgProfile = oData.data;
                            window.currentOrgName = oData.data.name;
                            window.currentOrgWebsite = oData.data.website_url;
                            if (oData.data.website_url) localStorage.setItem('edvora_org_website', oData.data.website_url);
                        }
                    } catch(e) {}
                }
                const res = await fetch('/v1/widget/customization?' + params, { headers: { 'Authorization': 'Bearer ' + token } });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    wcState.config = { ...WC_DEFAULTS, ...(data.data.config || {}) };
                    wcState.prerequisites = data.data.prerequisites || {
                        has_assets: false,
                        has_campuses: false,
                        has_callbacks: false,
                        lead_capture_enabled: false,
                        has_counselor_contact: false
                    };
                    const orgName = window.currentOrgName || (window.currentOrgProfile && window.currentOrgProfile.name) || (localStorage.getItem('edvora_org_name') || 'our university');
                    const dynDefaultWelcome = "Hi there! 👋 Welcome to " + orgName + ". Ask me anything about degree programs, admissions, eligibility, fees, or campus life!";
                    if (!wcState.config.header_bot_name || wcState.config.header_bot_name === 'Edvora AI' || wcState.config.header_bot_name === 'Edvora Chat' || wcState.config.header_bot_name === 'Campus Assistant' || wcState.config.header_bot_name === 'LeadBot' || wcState.config.header_bot_name === 'AI Admissions Assistant') {
                        wcState.config.header_bot_name = orgName;
                    }
                    if (!wcState.config.welcome_message || 
                        wcState.config.welcome_message.includes('University Admissions Assistant') || 
                        wcState.config.welcome_message.includes('Tanya') || 
                        wcState.config.welcome_message.includes('How can I assist you') || 
                        wcState.config.welcome_message.includes("I'm the AI Student Assistant") || 
                        wcState.config.welcome_message.includes('our admissions assistant') || 
                        wcState.config.welcome_message.includes('our assistant console') ||
                        wcState.config.welcome_message.includes('our university') ||
                        wcState.config.welcome_message.includes('our institution')) {
                        wcState.config.welcome_message = dynDefaultWelcome;
                    }
                    if (!wcState.config.launcher_icon || wcState.config.launcher_icon === 'chat') {
                        wcState.config.launcher_icon = 'modern_chat';
                    }

                    // Dynamically resolve quick chips (blank by default):
                    let initialChips = (wcState.config && wcState.config.quick_chips !== undefined && wcState.config.quick_chips !== null) ? wcState.config.quick_chips : '';
                    const chipParts = (Array.isArray(initialChips) ? initialChips : String(initialChips).split(','))
                        .map(s => (typeof s === 'object' && s !== null) ? (s.label || s.message || '') : String(s).trim())
                        .filter(Boolean);
                    currentWidgetBot.quick_chips = chipParts;
                    wcState.config.quick_chips = chipParts.join(', ');

                    const badge = document.getElementById('wcOverrideBadge');
                    if (badge) badge.style.display = 'none';
                    try { wcPopulateControls(wcState.config); } catch(errControls) { console.error('Error in wcPopulateControls:', errControls); }
                    try { applyWcToPreview(wcState.config); } catch(errPreview) { console.error('Error in applyWcToPreview:', errPreview); }
                    if (typeof wcSyncPreviewChips === 'function') wcSyncPreviewChips();
                } else {
                    try { applyWcToPreview(wcState.config); } catch(_) {}
                }
            } catch(e) {
                console.error('WC load error:', e);
                try { applyWcToPreview(wcState.config); } catch(_) {}
            } finally {
                const loader = document.getElementById('wcChatLoadingOverlay');
                if (loader) loader.style.display = 'none';
            }
        }

        async function wcPopulateDepts() {
            // Departments deprecated - retained as graceful no-op
            wcState.depts = [];
            const sel = document.getElementById('wcDeptSelect');
            if (sel) sel.innerHTML = '<option value="">All Institutional Departments</option>';
        }

        async function saveWidgetCustomization(silent = false) {
            if (!wcState.botId) { showToast('No chatbot selected.', 'error'); return; }

            // Sync quick_chips from the input field before saving
            const chipsEl = document.getElementById('quickChipsInput');
            if (chipsEl) {
                const chipsVal = chipsEl.value.trim();
                wcState.config.quick_chips = chipsVal;
                currentWidgetBot.quick_chips = chipsVal.split(',').map(s => s.trim()).filter(Boolean);
            }

            const btn = document.getElementById('wcSaveBtn') || document.getElementById('saveWidgetConfigBtn');
            if (btn && !silent) { btn.textContent = 'Saving...'; btn.disabled = true; }
            try {
                const res = await fetch('/v1/widget/customization', {
                    method: 'PUT',
                    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ bot_id: wcState.botId, scope: 'org', dept_id: null, config: wcState.config })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    // Also persist quick_chips to the chatbot record
                    if (!silent && currentWidgetBot.id && typeof saveChatbotWidgetConfig === 'function') {
                        await saveChatbotWidgetConfig();
                        return; // saveChatbotWidgetConfig handles its own success messaging
                    }
                    if (!silent) showToast('Widget customization saved & deployed! ✓', 'success');
                } else {
                    showToast(data.message || 'Save failed', 'error');
                }
            } catch(e) { showToast('Network error', 'error'); }
            finally { if (btn) { btn.textContent = '💾 Save & Apply Customization'; btn.disabled = false; } }
        }

        function applyWcToPreview(c) {
            if (!c) c = wcState.config || {};
            const el = id => document.getElementById(id);
            const win = el('wcChatWindow');
            if (!win) return;

            // Hide loading overlay once config is applied
            const loader = el('wcChatLoadingOverlay');
            if (loader) loader.style.display = 'none';

            // 1. Window styling
            win.style.background = c.window_bg_color || '#ffffff';
            win.style.borderColor = c.window_border_color || '#e2e8f0';
            win.style.borderWidth = (c.window_border_width !== undefined ? c.window_border_width : 1) + 'px';
            win.style.borderStyle = (c.window_border_width > 0) ? 'solid' : 'none';
            win.style.borderRadius = (c.window_border_radius !== undefined ? c.window_border_radius : 16) + 'px';
            win.style.width = (c.window_width || 380) + 'px';
            win.style.height = (c.window_height || 550) + 'px';
            win.style.boxShadow = WC_SHADOW_MAP[c.window_shadow] || WC_SHADOW_MAP.soft;

            // 2. Header & Branding
            const hdr = el('wcPreviewHeader');
            if (hdr) {
                hdr.style.background = c.header_bg || '#063D3B';
                hdr.style.color = c.header_text_color || '#FFFFFF';
            }
            const bn = el('wcPreviewBotName');
            const orgName = window.currentOrgName || (window.currentOrgProfile && window.currentOrgProfile.name) || (localStorage.getItem('edvora_org_name') || 'AI Admissions Assistant');
            const botName = (c.header_bot_name && c.header_bot_name !== 'AI Admissions Assistant' && c.header_bot_name !== 'Edvora AI' && c.header_bot_name !== 'Edvora Chat' && c.header_bot_name !== 'Campus Assistant' && c.header_bot_name !== 'LeadBot') ? c.header_bot_name : orgName;
            if (bn) {
                bn.textContent = botName;
                bn.style.color = c.header_text_color || '#FFFFFF';
            }
            const urlBar = el('wcBrowserUrl') || document.querySelector('.wc-browser-url');
            if (urlBar) {
                const webUrl = (window.currentOrgProfile && window.currentOrgProfile.website_url) ? window.currentOrgProfile.website_url.trim() : (window.currentOrgWebsite || '');
                urlBar.textContent = webUrl ? (webUrl.startsWith('http') ? webUrl : 'https://' + webUrl) : 'https://yourcollege.edu';
            }
            const sub = el('wcPreviewSubtitle');
            if (sub) {
                sub.textContent = (c.header_subtitle !== undefined && c.header_subtitle !== '') ? c.header_subtitle : 'Online Now';
                sub.style.color = c.header_text_color || '#FFFFFF';
            }
            const close = el('wcPreviewClose');
            if (close) {
                close.style.color = c.header_text_color || '#FFFFFF';
            }

            // 3. Header Logo / Avatar (framed with crisp white boundary)
            const avImg = el('wcPreviewAvatarImg');
            const avBox = el('wcPreviewAvatarBox');
            const logoUrl = c.header_logo_url || '/default_logo.png';
            if (avImg) avImg.src = logoUrl;
            if (avBox) {
                avBox.style.display = 'flex';
                avBox.style.background = '#FFFFFF';
                avBox.style.border = '1px solid #FFFFFF';
                avBox.style.borderRadius = '4px';
                avBox.style.boxShadow = '0 1px 2px rgba(0,0,0,0.12)';
            }

            // 4. Message bubble avatars
            const showBubbleAv = (c.avatar_location === 'bubbles' || c.avatar_location === 'both' || !c.avatar_location);
            const avBubbleSrc = (c.avatar_type === 'upload' && c.avatar_url) ? c.avatar_url : '/avatars/avatar' + (c.avatar_preset || 1) + '.png';
            ['wcMsgAv1','wcMsgAv2'].forEach(avId => {
                const av = el(avId);
                if (!av) return;
                av.style.display = showBubbleAv ? 'flex' : 'none';
                const img = av.querySelector('img');
                if (img) img.src = avBubbleSrc;
            });

            // 5. Message area background
            const msgs = el('wcPreviewMessages');
            if (msgs) msgs.style.background = c.message_area_bg || '#f9fafb';

            // 6. Bot bubbles
            ['wcPreviewWelcomeBubble','wcPreviewBotReply'].forEach(id => {
                const b = el(id);
                if (b) {
                    b.style.background = c.bot_bubble_bg || '#f1f5f9';
                    b.style.color = c.bot_bubble_text || '#1e293b';
                    const rad = (c.bot_bubble_radius !== undefined) ? c.bot_bubble_radius : 14;
                    b.style.borderRadius = `${rad}px ${rad}px ${rad}px 3px`;
                    b.style.fontSize = (c.message_font_size || 13) + 'px';
                }
            });

            // 7. Welcome message content
            const wb = el('wcPreviewWelcomeBubble');
            if (wb) {
                const defaultMsg = "Hi there! 👋 Welcome to " + (window.currentOrgName || (window.currentOrgProfile && window.currentOrgProfile.name) || (localStorage.getItem('edvora_org_name') || "our university")) + ". Ask me anything about degree programs, admissions, eligibility, fees, or campus life!";
                let rawMsg = c.welcome_message || defaultMsg;
                if (rawMsg.includes('our university') || rawMsg.includes('our institution')) {
                    rawMsg = defaultMsg;
                }
                wb.innerHTML = rawMsg.replace(/\n/g, '<br>');
            }

            // 8. User bubbles & bot reply sample
            const userBubbleEl = el('wcPreviewUserBubble');
            if (userBubbleEl && (userBubbleEl.textContent.includes('Tell me about fees') || userBubbleEl.textContent.includes('tell me about fees'))) {
                userBubbleEl.textContent = 'What programs and degrees are offered?';
            }
            const botReplyEl = el('wcPreviewBotReply');
            if (botReplyEl && (botReplyEl.textContent.includes('B.Tech') || botReplyEl.textContent.includes('1.2L'))) {
                botReplyEl.textContent = 'We offer undergraduate and graduate degree programs across multiple academic colleges. How can I help you explore? ðŸŽ“';
            }

            document.querySelectorAll('#wcPreviewMessages .wc-msg-row.user .wc-bubble, #wcPreviewUserBubble').forEach(b => {
                b.style.background = c.user_bubble_bg || '#063D3B';
                b.style.color = c.user_bubble_text || '#FFFFFF';
                const rad = (c.user_bubble_radius !== undefined) ? c.user_bubble_radius : 14;
                b.style.borderRadius = `${rad}px ${rad}px 3px ${rad}px`;
                b.style.fontSize = (c.message_font_size || 13) + 'px';
            });

            // 9. Quick prompt chips
            const chipsBox = el('wcPreviewChips');
            if (chipsBox) {
                const cInput = document.getElementById('quickChipsInput');
                let rawChips = '';
                if (cInput) {
                    rawChips = cInput.value.trim();
                } else if (c && c.quick_chips !== undefined && c.quick_chips !== null) {
                    rawChips = c.quick_chips;
                }

                let chipsArr = [];
                if (Array.isArray(rawChips)) {
                    chipsArr = rawChips.map(ch => (typeof ch === 'object' && ch !== null) ? (ch.label || ch.message || '') : String(ch).trim()).filter(Boolean);
                } else if (typeof rawChips === 'string' && rawChips.trim() !== '') {
                    try {
                        const parsed = JSON.parse(rawChips);
                        if (Array.isArray(parsed)) {
                            chipsArr = parsed.map(ch => (typeof ch === 'object' && ch !== null) ? (ch.label || ch.message || '') : String(ch).trim()).filter(Boolean);
                        } else {
                            chipsArr = rawChips.split(',').map(s => s.trim()).filter(Boolean);
                        }
                    } catch(_) {
                        chipsArr = rawChips.split(',').map(s => s.trim()).filter(Boolean);
                    }
                }

                if (chipsArr.length === 0) {
                    chipsBox.innerHTML = '';
                    chipsBox.style.display = 'none';
                } else {
                    chipsBox.style.display = 'flex';
                    chipsBox.innerHTML = chipsArr.map(chip => `
                        <span class="wc-chip-item" style="border-color:${c.chip_border_color || '#063D3B'}; color:${c.chip_text_color || '#063D3B'}; background:${c.chip_bg || 'transparent'}; border-radius:${c.chip_border_radius !== undefined ? c.chip_border_radius : 20}px; cursor:pointer;" onclick="wcPreviewClickChip('${chip.replace(/'/g, "\\'")}')">
                            ${chip}
                        </span>
                    `).join('');
                }
            }

            // 10. Input row & Send button
            const ir = el('wcPreviewInputRow');
            if (ir) {
                ir.style.background = c.input_bg || '#ffffff';
                ir.style.borderTopColor = c.input_border_color || '#e2e8f0';
            }
            const inf = el('wcPreviewInput');
            if (inf) {
                inf.style.background = c.input_bg || '#ffffff';
                inf.style.borderColor = c.input_border_color || '#e2e8f0';
                inf.style.color = c.input_text_color || '#1e293b';
                inf.style.borderRadius = (c.input_border_radius !== undefined ? c.input_border_radius : 24) + 'px';
                if (c.input_placeholder) inf.placeholder = c.input_placeholder;
            }
            const sb = el('wcPreviewSendBtn');
            if (sb) {
                sb.style.background = c.send_btn_bg || c.header_bg || '#063D3B';
                sb.style.color = c.send_btn_icon_color || '#FFFFFF';
            }



            // 12. Floating Launcher Button
            const lnch = el('wcPreviewLauncher');
            if (lnch) {
                lnch.className = 'wc-launcher style-' + (c.launcher_style || 'circle') + ' pos-' + (c.launcher_position || 'bottom-right');
                lnch.style.background = c.launcher_bg || c.header_bg || '#063D3B';
                lnch.style.color = c.launcher_icon_color || '#C8FF63';
                if (c.launcher_style === 'pill') {
                    lnch.style.width = 'auto';
                    lnch.style.height = (c.launcher_size || 60) + 'px';
                    lnch.style.minWidth = (c.launcher_size || 60) + 'px';
                } else {
                    lnch.style.width = (c.launcher_size || 60) + 'px';
                    lnch.style.height = (c.launcher_size || 60) + 'px';
                }
                const li = el('wcPreviewLauncherIcon');
                const lt = el('wcPreviewLauncherText');
                if (li) li.innerHTML = WC_LAUNCHER_ICONS[c.launcher_icon] || 'ðŸ’¬';
                if (lt) {
                    lt.style.display = (c.launcher_style === 'pill') ? 'inline' : 'none';
                    lt.textContent = c.launcher_text || 'Ask AI';
                    lt.style.color = c.launcher_icon_color || '#C8FF63';
                }
            }

            // Update all slider tracks fill
            document.querySelectorAll('.wc-slider').forEach(s => wcUpdateSliderFill(s));
        }

        function wcPopulateControls(c) {
            const fields = {
                header_bg: ['wc_header_bg_picker','wc_header_bg_hex'],
                header_text_color: ['wc_header_text_picker','wc_header_text_hex'],
                window_bg_color: ['wc_window_bg_picker','wc_window_bg_hex'],
                window_border_color: ['wc_border_color_picker','wc_border_color_hex'],
                bot_bubble_bg: ['wc_bot_bubble_bg_picker','wc_bot_bubble_bg_hex'],
                bot_bubble_text: ['wc_bot_bubble_text_picker','wc_bot_bubble_text_hex'],
                user_bubble_bg: ['wc_user_bubble_bg_picker','wc_user_bubble_bg_hex'],
                user_bubble_text: ['wc_user_bubble_text_picker','wc_user_bubble_text_hex'],
                message_area_bg: ['wc_msg_area_bg_picker','wc_msg_area_bg_hex'],
                input_bg: ['wc_input_bg_picker','wc_input_bg_hex'],
                input_border_color: ['wc_input_border_picker','wc_input_border_hex'],
                input_text_color: ['wc_input_text_picker','wc_input_text_hex'],
                send_btn_bg: ['wc_send_btn_picker','wc_send_btn_hex'],
                send_btn_icon_color: ['wc_send_icon_picker','wc_send_icon_hex'],
                launcher_bg: ['wc_launcher_bg_picker','wc_launcher_bg_hex'],
                launcher_icon_color: ['wc_launcher_icon_color_picker','wc_launcher_icon_color_hex'],
                chip_border_color: ['wc_chip_border_picker','wc_chip_border_hex'],
                chip_text_color: ['wc_chip_text_picker','wc_chip_text_hex']
            };
            Object.entries(fields).forEach(([field, [pid, hid]]) => {
                const v = c[field] || '';
                const pk = document.getElementById(pid), hx = document.getElementById(hid);
                if (pk && v) {
                    let hex = v.trim();
                    if (hex.startsWith('#') && (hex.length === 7 || hex.length === 4)) {
                        if (hex.length === 4) {
                            hex = '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3];
                        }
                        pk.value = hex;
                    }
                    if (pk.parentElement) pk.parentElement.style.background = v;
                }
                if (hx) hx.value = v;
            });

            wcSetSlider('wc_window_width', c.window_width || 380, 'wc_window_width_val', 'px');
            wcSetSlider('wc_window_height', c.window_height || 550, 'wc_window_height_val', 'px');
            wcSetSlider('wc_border_width', c.window_border_width !== undefined ? c.window_border_width : 1, 'wc_border_width_val', 'px');
            wcSetSlider('wc_border_radius', c.window_border_radius !== undefined ? c.window_border_radius : 16, 'wc_border_radius_val', 'px');
            wcSetSlider('wc_bot_bubble_radius', c.bot_bubble_radius !== undefined ? c.bot_bubble_radius : 14, 'wc_bot_bubble_radius_val', 'px');
            wcSetSlider('wc_user_bubble_radius', c.user_bubble_radius !== undefined ? c.user_bubble_radius : 14, 'wc_user_bubble_radius_val', 'px');
            wcSetSlider('wc_input_radius', c.input_border_radius !== undefined ? c.input_border_radius : 24, 'wc_input_radius_val', 'px');
            wcSetSlider('wc_launcher_size', c.launcher_size !== undefined ? c.launcher_size : 60, 'wc_launcher_size_val', 'px');
            wcSetSlider('wc_chip_radius', c.chip_border_radius !== undefined ? c.chip_border_radius : 20, 'wc_chip_radius_val', 'px');

            const sv = (id, v) => { const e = document.getElementById(id); if (e) e.value = v || ''; };
            const orgName = window.currentOrgName || (window.currentOrgProfile && window.currentOrgProfile.name) || (localStorage.getItem('edvora_org_name') || 'AI Admissions Assistant');
            sv('wc_header_bot_name', (c.header_bot_name && c.header_bot_name !== 'AI Admissions Assistant' && c.header_bot_name !== 'Edvora AI' && c.header_bot_name !== 'Edvora Chat' && c.header_bot_name !== 'Campus Assistant' && c.header_bot_name !== 'LeadBot') ? c.header_bot_name : orgName); 
            sv('wc_header_subtitle', c.header_subtitle || 'Online Now');
            const defWelcome = "Hi there! 👋 Welcome to " + orgName + ". Ask me anything about degree programs, admissions, eligibility, fees, or campus life!";
            let curWel = c.welcome_message || defWelcome;
            if (curWel.includes('our university') || curWel.includes('our institution')) {
                curWel = defWelcome;
            }
            sv('wc_welcome_message', curWel);
            sv('quickChipsInput', Array.isArray(c.quick_chips) ? c.quick_chips.join(', ') : (c.quick_chips || ''));

            const iconVal = c.launcher_icon || 'modern_chat';
            wcSetRadio('wcLauncherStyleGroup', c.launcher_style || 'circle');
            wcSetRadio('wcThemeGroup', c.theme || 'light');
            wcSetRadio('wcAvatarLocationGroup', c.avatar_location || 'bubbles');

            const br = document.getElementById('wc_show_branding');
            if (br) br.checked = c.show_branding !== undefined ? !!c.show_branding : true;



            for (let i = 1; i <= 11; i++) {
                const opt = document.getElementById('wcAv'+i);
                if (opt) opt.classList.toggle('active', (c.avatar_preset || 1) === i && (c.avatar_type || 'preset') === 'preset');
            }

            const img = document.getElementById('wcLogoPreviewImg');
            const rb = document.getElementById('wcLogoRemoveBtn');
            const st = document.getElementById('wcLogoStatusText');
            if (c.header_logo_url) {
                if (img) { img.src = c.header_logo_url; img.style.display = 'inline-block'; }
                if (rb) rb.style.display = 'inline-flex';
                if (st) { st.textContent = 'Custom logo active'; st.style.display = 'inline'; }
            } else {
                if (img) { img.src = '/default_logo.png'; img.style.display = 'inline-block'; }
                if (rb) rb.style.display = 'none';
                if (st) { st.textContent = 'Default institution logo active'; st.style.display = 'inline'; }
            }

            document.querySelectorAll('.wc-slider').forEach(s => wcUpdateSliderFill(s));
        }

        function wcSetSlider(sid, val, lid, unit) {
            const s = document.getElementById(sid), l = document.getElementById(lid);
            if (s) { s.value = val; wcUpdateSliderFill(s); }
            if (l) l.textContent = val + (unit || '');
        }

        function wcSetRadio(gid, val) {
            const g = document.getElementById(gid); if (!g) return;
            g.querySelectorAll('.wc-radio-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-val') === String(val)));
        }

        function wcUpdateSliderFill(slider) {
            if (!slider || slider.min === undefined || slider.max === undefined) return;
            const min = parseFloat(slider.min) || 0;
            const max = parseFloat(slider.max) || 100;
            const val = parseFloat(slider.value) || 0;
            const range = max - min;
            const pct = range > 0 ? Math.max(0, Math.min(100, ((val - min) / range) * 100)) : 0;
            slider.style.background = 'linear-gradient(to right, #063D3B 0%, #063D3B ' + pct + '%, #DCE9E5 ' + pct + '%)';
        }

        const WC_HEX_MAP = {
            header_bg: 'wc_header_bg_hex', header_text_color: 'wc_header_text_hex',
            window_bg_color: 'wc_window_bg_hex', window_border_color: 'wc_border_color_hex',
            bot_bubble_bg: 'wc_bot_bubble_bg_hex', bot_bubble_text: 'wc_bot_bubble_text_hex',
            user_bubble_bg: 'wc_user_bubble_bg_hex', user_bubble_text: 'wc_user_bubble_text_hex',
            message_area_bg: 'wc_msg_area_bg_hex', input_bg: 'wc_input_bg_hex',
            input_border_color: 'wc_input_border_hex', input_text_color: 'wc_input_text_hex',
            send_btn_bg: 'wc_send_btn_hex', send_btn_icon_color: 'wc_send_icon_hex',
            launcher_bg: 'wc_launcher_bg_hex', launcher_icon_color: 'wc_launcher_icon_color_hex',
            chip_border_color: 'wc_chip_border_hex', chip_text_color: 'wc_chip_text_hex'
        };

        function onWcColor(field, val) {
            wcState.config[field] = val;
            const hexId = WC_HEX_MAP[field];
            if (hexId) {
                const hexEl = document.getElementById(hexId);
                if (hexEl) hexEl.value = val;
            }
            const pickerEl = document.getElementById('wc_' + field + '_picker');
            if (pickerEl && pickerEl.parentElement) {
                pickerEl.parentElement.style.background = val;
            }
            applyWcToPreview(wcState.config);
        }

        function onWcHex(field, pickerId, hexVal) {
            if (!hexVal) return;
            hexVal = hexVal.trim();
            if (!hexVal.startsWith('#') && /^[0-9a-fA-F]{3,8}$/.test(hexVal)) {
                hexVal = '#' + hexVal;
            }
            if (/^#[0-9a-fA-F]{3,8}$/.test(hexVal)) {
                wcState.config[field] = hexVal;
                const pk = document.getElementById(pickerId);
                if (pk) {
                    if (hexVal.length === 7) pk.value = hexVal;
                    if (pk.parentElement) pk.parentElement.style.background = hexVal;
                }
                applyWcToPreview(wcState.config);
            }
        }

        function onWcSlider(field, val, labelId, unit) {
            const num = parseFloat(val);
            wcState.config[field] = num;
            const l = document.getElementById(labelId); if (l) l.textContent = num + unit;
            document.querySelectorAll('.wc-slider').forEach(s => wcUpdateSliderFill(s));
            applyWcToPreview(wcState.config);
        }

        function onWcText(field, val) {
            wcState.config[field] = val;
            if (field === 'quick_chips') {
                currentWidgetBot.quick_chips = (val || '').split(',').map(s => s.trim()).filter(Boolean);
            }
            applyWcToPreview(wcState.config);
        }

        function onWcSelect(field, val) {
            wcState.config[field] = val;
            applyWcToPreview(wcState.config);
        }

        function onWcToggle(field, val) {
            wcState.config[field] = val;
            applyWcToPreview(wcState.config);
        }

        function onWcLauncherStyle(val, el) {
            wcState.config.launcher_style = val;
            el.closest('.wc-radio-group').querySelectorAll('.wc-radio-btn').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
            const tr = document.getElementById('wcLauncherTextRow');
            if (tr) tr.style.display = (val === 'pill') ? 'block' : 'none';
            applyWcToPreview(wcState.config);
        }

        window.applyWcToPreview = applyWcToPreview;
        window.wcPopulateControls = wcPopulateControls;
        window.onWcColor = onWcColor;
        window.onWcHex = onWcHex;
        window.onWcSlider = onWcSlider;
        window.onWcText = onWcText;
        window.onWcSelect = onWcSelect;
        window.onWcToggle = onWcToggle;
        window.onWcLauncherStyle = onWcLauncherStyle;
        window.loadWidgetCustomization = loadWidgetCustomization;
        window.saveWidgetCustomization = saveWidgetCustomization;

        function toggleWcIconMenu(e) {
            if (e) e.stopPropagation();
            const menu = document.getElementById('wcLauncherIconMenu');
            if (menu) menu.classList.toggle('open');
        }

        function selectWcLauncherIcon(val, label) {
            wcState.config.launcher_icon = val;
            const iconLabels = {
                modern_chat: 'Modern Outline Bubble',
                chat: 'Classic Emoji Bubble',
                robot: 'Robot',
                sparkles: 'Sparkles',
                headset: 'Headset',
                star: 'Star'
            };
            const prev = document.getElementById('wcSelectedIconPreview');
            const lbl = document.getElementById('wcSelectedIconLabel');
            if (prev) prev.innerHTML = WC_LAUNCHER_ICONS[val] || 'ðŸ’¬';
            if (lbl) lbl.textContent = iconLabels[val] || label;
            const menu = document.getElementById('wcLauncherIconMenu');
            if (menu) {
                menu.classList.remove('open');
                menu.querySelectorAll('.wc-select-opt').forEach(o => o.classList.toggle('active', o.getAttribute('data-val') === val));
            }
            applyWcToPreview(wcState.config);
        }

        document.addEventListener('click', function(e) {
            const wrap = document.getElementById('wcLauncherIconDropdown');
            if (wrap && !wrap.contains(e.target)) {
                const menu = document.getElementById('wcLauncherIconMenu');
                if (menu) menu.classList.remove('open');
            }
        });

        function onWcTheme(val, el) {
            wcState.config.theme = val;
            el.closest('.wc-radio-group').querySelectorAll('.wc-radio-btn').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
            applyWcToPreview(wcState.config);
        }

        function onWcAvatarLocation(val, el) {
            wcState.config.avatar_location = val;
            el.closest('.wc-radio-group').querySelectorAll('.wc-radio-btn').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
            applyWcToPreview(wcState.config);
        }

        function selectWcAvatarPreset(num) {
            wcState.config.avatar_type = 'preset';
            wcState.config.avatar_preset = num;
            wcState.config.avatar_url = null;
            for (let i = 1; i <= 11; i++) { const e = document.getElementById('wcAv'+i); if (e) e.classList.toggle('active', i === num); }
            applyWcToPreview(wcState.config);
        }

        async function onWcAvatarUpload(input) {
            const file = input.files[0]; if (!file) return;
            if (file.size > 2*1024*1024) { showToast('Image must be under 2MB','error'); return; }
            const url = await wcUploadImage(file,'avatar');
            if (url) {
                wcState.config.avatar_type = 'upload'; wcState.config.avatar_url = url;
                for (let i = 1; i <= 11; i++) { const e = document.getElementById('wcAv'+i); if (e) e.classList.remove('active'); }
                applyWcToPreview(wcState.config);
                showToast('Avatar uploaded!','success');
            }
        }

        async function onWcLogoUpload(input) {
            const file = input.files[0]; if (!file) return;
            if (file.size > 2*1024*1024) { showToast('Image must be under 2MB','error'); return; }
            const url = await wcUploadImage(file,'logo');
            if (url) {
                wcState.config.header_logo_url = url;
                const img = document.getElementById('wcLogoPreviewImg'), rb = document.getElementById('wcLogoRemoveBtn'), st = document.getElementById('wcLogoStatusText');
                if (img) { img.src = url; img.style.display = 'block'; }
                if (rb) rb.style.display = 'inline'; if (st) st.style.display = 'none';
                applyWcToPreview(wcState.config);
                if (!wcState.botId && currentWidgetBot && currentWidgetBot.id) {
                    wcState.botId = currentWidgetBot.id;
                }
                await saveWidgetCustomization(true);
                showToast('Logo uploaded & saved! âœ“','success');
            }
        }

        async function removeWcLogo() {
            wcState.config.header_logo_url = null;
            const img = document.getElementById('wcLogoPreviewImg'), rb = document.getElementById('wcLogoRemoveBtn'), st = document.getElementById('wcLogoStatusText');
            if (img) { img.src='/default_logo.png'; img.style.display='inline-block'; }
            if (rb) rb.style.display='none'; if (st) { st.textContent = 'Default institution logo active'; st.style.display='inline'; }
            applyWcToPreview(wcState.config);
            if (!wcState.botId && currentWidgetBot && currentWidgetBot.id) {
                wcState.botId = currentWidgetBot.id;
            }
            await saveWidgetCustomization(true);
            showToast('Reset to default institution logo.','info');
        }

        function toggleWcSection(id) { 
            const s = document.getElementById(id); 
            if (!s) return;
            const wasCollapsed = s.classList.contains('collapsed');
            
            // Accordion behavior: close other sections for clean focus
            document.querySelectorAll('.wc-section').forEach(sec => {
                if (sec.id !== id) sec.classList.add('collapsed');
            });
            
            if (wasCollapsed) {
                s.classList.remove('collapsed');
                setTimeout(() => {
                    s.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 50);
            } else {
                s.classList.add('collapsed');
            }
        }

        async function wcUploadImage(file, type) {
            const fd = new FormData(); fd.append('image', file);
            try {
                const res = await fetch('/v1/widget/avatar/upload?type='+type, { method:'POST', headers:{'Authorization':'Bearer '+token}, body:fd });
                const data = await res.json();
                if (data.status === 'success') return data.data.url;
                showToast(data.message || 'Upload failed','error'); return null;
            } catch(e) { showToast('Upload error','error'); return null; }
        }

        async function onWcScopeChange(scope) {
            wcState.scope = 'org';
            wcState.dept_id = null;
            const orgBtn = document.getElementById('wcScopeOrgBtn');
            if (orgBtn) orgBtn.classList.add('active');
            const deptBtn = document.getElementById('wcScopeDeptBtn');
            if (deptBtn) deptBtn.classList.remove('active');
            const dr = document.getElementById('wcDeptRow');
            if (dr) dr.style.display = 'none';
            const badge = document.getElementById('wcOverrideBadge');
            if (badge) badge.style.display = 'none';
            await loadWidgetCustomization();
        }

        async function onWcDeptChange(deptId) {
            wcState.dept_id = null;
            await loadWidgetCustomization();
        }

        function goToDeptChatbotSettings(directDeptId = null) {
            switchNavTab('chatbot', 'widget');
        }

        function goToDeptEmbedCode(deptId) {
            switchNavTab('chatbot', 'embed');
        }




        // showToast() defined in js/app-core.js (do not duplicate here)


        async function resetWcConfig() {
            wcState.config = { ...WC_DEFAULTS };
            const orgName = window.currentOrgName || (window.currentOrgProfile && window.currentOrgProfile.name) || (localStorage.getItem('edvora_org_name') || 'Institution');
            wcState.config.header_bot_name = orgName;
            wcPopulateControls(wcState.config);
            applyWcToPreview(wcState.config);
            wcSyncPreviewChips();
            await saveWidgetCustomization(true);
            showToast('All customizations reset to defaults & applied! âœ“', 'success');
        }

        function wcHandleKey(e) { if (e.key === 'Enter') wcSendMessage(); }

        function wcSendMessage() {
            const input = document.getElementById('wcPreviewInput');
            const msg = input ? input.value.trim() : ''; if (!msg) return;
            const c = wcState.config;
            const msgArea = document.getElementById('wcPreviewMessages'); if (!msgArea) return;
            const row = document.createElement('div'); row.className = 'wc-msg-row user';
            row.innerHTML = '<div class="wc-bubble" style="background:'+c.user_bubble_bg+'; color:'+c.user_bubble_text+'; border-radius:'+c.user_bubble_radius+'px '+c.user_bubble_radius+'px 3px '+c.user_bubble_radius+'px; font-size:'+c.message_font_size+'px;">'+msg+'</div>';
            msgArea.appendChild(row);
            if (input) input.value = '';
            msgArea.scrollTop = msgArea.scrollHeight;
        }

        function wcSyncPreviewChips() {
            const chipsEl = document.getElementById('quickChipsInput');
            let rawChips = '';
            if (chipsEl) {
                rawChips = chipsEl.value.trim();
            } else if (wcState.config && wcState.config.quick_chips !== undefined && wcState.config.quick_chips !== null) {
                rawChips = wcState.config.quick_chips;
            }

            let arr = [];
            if (Array.isArray(rawChips)) {
                arr = rawChips.map(c => (typeof c === 'object' && c !== null) ? (c.label || c.message || '') : String(c).trim()).filter(Boolean);
            } else if (typeof rawChips === 'string' && rawChips.trim() !== '') {
                try {
                    const parsed = JSON.parse(rawChips);
                    if (Array.isArray(parsed)) {
                        arr = parsed.map(c => (typeof c === 'object' && c !== null) ? (c.label || c.message || '') : String(c).trim()).filter(Boolean);
                    } else {
                        arr = rawChips.split(',').map(s => s.trim()).filter(Boolean);
                    }
                } catch(_) {
                    arr = rawChips.split(',').map(s => s.trim()).filter(Boolean);
                }
            }

            const row = document.getElementById('wcPreviewChips'); if (!row) return;
            if (arr.length === 0) {
                row.innerHTML = '';
                row.style.display = 'none';
                return;
            }
            row.style.display = 'flex';
            const c = wcState.config || {};
            const bColor = c.chip_border_color || '#063D3B';
            const tColor = c.chip_text_color || '#063D3B';
            const bg = c.chip_bg || 'transparent';
            const rad = c.chip_border_radius !== undefined ? c.chip_border_radius : 20;

            row.innerHTML = arr.slice(0, 5).map(ch => 
                `<span class="wc-chip-item" style="border-color:${bColor}; color:${tColor}; background:${bg}; border-radius:${rad}px; cursor:pointer;" onclick="wcPreviewClickChip('${ch.replace(/'/g, "\\'")}')">${ch}</span>`
            ).join('');
        }

        function wcPreviewClickChip(text) {
            const input = document.getElementById('wcPreviewInput');
            if (input) {
                input.value = text;
                wcSendMessage();
            }
        }
        window.wcPreviewClickChip = wcPreviewClickChip;

        // Ensure default 30d time window is visually primed
        try {
            if (typeof applyTimeWindowUI === 'function') {
                applyTimeWindowUI('30d');
            }
        } catch(_) {}

        // Global Command+K / Ctrl+K search shortcut listener
        document.addEventListener('keydown', function(e) {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                const currentHash = window.location.hash || '';
                if (currentHash.includes('#leads')) {
                    const leadsInput = document.getElementById('leadsSearchInput');
                    if (leadsInput) {
                        e.preventDefault();
                        leadsInput.focus();
                        leadsInput.select();
                    }
                } else if (currentHash.includes('#knowledge')) {
                    const ksInput = document.getElementById('ksSearchInput');
                    if (ksInput) {
                        e.preventDefault();
                        ksInput.focus();
                        ksInput.select();
                    }
                }
            }
        });
