/**
 * Universal Registration Email Verification & Google Helper
 * Provides universal Google Identity linking, email autofill, and email OTP verification
 * for all registration pages:
 * - /user/registration/
 * - /uncle/registration/
 * - /uncle/church/registration/
 */

(function(window) {
    'use strict';

    const GOOGLE_CLIENT_ID = '384251465276-lu14sul99cfm36p94a3bbg5aq9jp5fm4.apps.googleusercontent.com';

    function decodeJwt(token) {
        try {
            const base64Url = token.split('.')[1];
            const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
            const jsonPayload = decodeURIComponent(atob(base64).split('').map(function(c) {
                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));
            return JSON.parse(jsonPayload);
        } catch(e) {
            return null;
        }
    }

    function injectStyles() {
        if (document.getElementById('grh-styles')) return;
        const style = document.createElement('style');
        style.id = 'grh-styles';
        style.textContent = `
            .grh-otp-card {
                background: var(--surface-2, #f8fafc);
                border: 1px solid var(--border-solid, #e2e8f0);
                border-radius: var(--r-lg, 16px);
                padding: 16px;
                margin-top: 6px;
                margin-bottom: 8px;
                transition: all var(--t, 0.22s) var(--ease, ease);
                box-sizing: border-box;
                width: 100%;
                font-family: 'Cairo', sans-serif;
            }
            .grh-otp-card.verified {
                background: var(--success-bg, rgba(16, 185, 129, 0.1));
                border-color: rgba(16, 185, 129, 0.35);
            }
            .grh-otp-header {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 0.86rem;
                font-weight: 700;
                color: var(--text, #1e293b);
                margin-bottom: 10px;
            }
            .grh-otp-header i {
                color: var(--brand, #5b6cf5);
                font-size: 0.95rem;
            }
            .grh-otp-hint {
                font-size: 0.8rem;
                color: var(--text-3, #64748b);
                line-height: 1.6;
                margin-bottom: 12px;
            }
            .grh-btn-action {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 10px 18px;
                border-radius: var(--r-md, 12px);
                font-family: 'Cairo', sans-serif;
                font-size: 0.88rem;
                font-weight: 700;
                cursor: pointer;
                border: none;
                transition: all var(--t, 0.22s) var(--ease, ease);
                text-decoration: none;
            }
            .grh-btn-send {
                background: linear-gradient(135deg, var(--brand, #5b6cf5), var(--brand-dark, #4354e8));
                color: #ffffff;
                box-shadow: 0 4px 14px var(--brand-glow, rgba(91, 108, 245, 0.22));
                width: 100%;
            }
            .grh-btn-send:hover:not(:disabled) {
                transform: translateY(-1px);
                box-shadow: 0 6px 18px var(--brand-glow, rgba(91, 108, 245, 0.3));
            }
            .grh-btn-send:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none;
            }
            .grh-otp-sent-alert {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 10px 14px;
                background: var(--brand-bg, #eef0ff);
                border: 1px solid var(--border, rgba(91, 108, 245, 0.2));
                border-radius: var(--r-sm, 10px);
                font-size: 0.82rem;
                color: var(--brand-dark, #4354e8);
                font-weight: 600;
                margin-bottom: 12px;
            }
            .grh-otp-inputs-row {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 10px;
            }
            @media (max-width: 480px) {
                .grh-otp-inputs-row {
                    flex-direction: column;
                }
                .grh-otp-inputs-row .grh-btn-verify {
                    width: 100%;
                }
            }
            .grh-otp-input-wrap {
                position: relative;
                flex: 1;
                width: 100%;
            }
            .grh-otp-code-input {
                width: 100%;
                padding: 12px 14px;
                font-family: 'Cairo', sans-serif;
                font-size: 1.25rem;
                font-weight: 800;
                letter-spacing: 6px;
                text-align: center;
                direction: ltr;
                background: var(--surface, #ffffff);
                border: 1.5px solid var(--border-solid, #cbd5e1);
                border-radius: var(--r-md, 12px);
                color: var(--text, #1e293b);
                outline: none;
                transition: border-color 0.2s, box-shadow 0.2s;
                box-sizing: border-box;
            }
            .grh-otp-code-input:focus {
                border-color: var(--brand, #5b6cf5);
                box-shadow: 0 0 0 3px var(--brand-glow, rgba(91, 108, 245, 0.18));
            }
            .grh-btn-verify {
                background: linear-gradient(135deg, var(--brand, #5b6cf5), var(--brand-dark, #4354e8));
                color: #ffffff;
                padding: 12px 20px;
                font-size: 0.9rem;
                flex-shrink: 0;
            }
            .grh-btn-verify:hover:not(:disabled) {
                transform: translateY(-1px);
                box-shadow: 0 4px 14px var(--brand-glow, rgba(91, 108, 245, 0.25));
            }
            .grh-btn-verify:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .grh-otp-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 8px;
                padding-top: 4px;
            }
            .grh-btn-resend {
                background: none;
                border: none;
                color: var(--text-2, #475569);
                font-family: 'Cairo', sans-serif;
                font-size: 0.78rem;
                font-weight: 700;
                cursor: pointer;
                padding: 4px 6px;
                border-radius: var(--r-xs, 6px);
                display: inline-flex;
                align-items: center;
                gap: 6px;
                transition: color 0.2s;
            }
            .grh-btn-resend:hover:not(:disabled) {
                color: var(--brand, #5b6cf5);
            }
            .grh-btn-resend:disabled {
                color: var(--text-3, #94a3b8);
                cursor: not-allowed;
            }
            .grh-otp-verified-card {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 10px 14px;
                background: #dcfce7;
                border: 1px solid #86efac;
                border-radius: var(--r-md, 12px);
                color: #15803d;
                font-size: 0.85rem;
                font-weight: 700;
                animation: fadeUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            }
            [data-theme="dark"] .grh-otp-verified-card {
                background: rgba(16, 185, 129, 0.15);
                border-color: rgba(16, 185, 129, 0.3);
                color: #34d399;
            }
            .grh-verified-info {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .grh-verified-info i {
                font-size: 1.1rem;
            }
            .grh-btn-change {
                background: none;
                border: none;
                color: inherit;
                font-family: inherit;
                font-size: 0.78rem;
                font-weight: 800;
                text-decoration: underline;
                cursor: pointer;
                padding: 2px 6px;
                border-radius: 4px;
            }
            .grh-btn-change:hover {
                opacity: 0.8;
            }
            .grh-otp-error {
                margin-top: 8px;
                padding: 8px 12px;
                background: var(--danger-bg, #fee2e2);
                color: var(--danger-dark, #dc2626);
                border: 1px solid rgba(239, 68, 68, 0.2);
                border-radius: var(--r-xs, 6px);
                font-size: 0.78rem;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 6px;
            }
        `;
        document.head.appendChild(style);
    }

    const GoogleRegHelper = {
        clientId: GOOGLE_CLIENT_ID,
        _loadedGsi: false,

        ensureGsi: function(callback) {
            if (typeof google !== 'undefined' && google.accounts) {
                if (callback) callback();
                return;
            }
            if (!document.querySelector('script[src*="accounts.google.com/gsi/client"]')) {
                const s = document.createElement('script');
                s.src = 'https://accounts.google.com/gsi/client';
                s.async = true;
                s.defer = true;
                s.onload = function() {
                    GoogleRegHelper._loadedGsi = true;
                    if (callback) callback();
                };
                document.head.appendChild(s);
            } else {
                const checkInterval = setInterval(function() {
                    if (typeof google !== 'undefined' && google.accounts) {
                        clearInterval(checkInterval);
                        if (callback) callback();
                    }
                }, 100);
            }
        },

        requestAccount: function(onSuccess, onError) {
            this.ensureGsi(function() {
                try {
                    let resolved = false;
                    const finish = function(data) {
                        if (resolved) return;
                        resolved = true;
                        if (onSuccess) onSuccess(data);
                    };

                    google.accounts.id.initialize({
                        client_id: GoogleRegHelper.clientId,
                        callback: function(resp) {
                            if (resp && resp.credential) {
                                const payload = decodeJwt(resp.credential);
                                if (payload && payload.email) {
                                    finish({
                                        email: payload.email,
                                        name: payload.name || '',
                                        google_id: payload.sub || '',
                                        credential: resp.credential
                                    });
                                } else if (onError) {
                                    onError('تعذر قراءة البريد الإلكتروني من حساب Google');
                                }
                            }
                        },
                        auto_select: false,
                        cancel_on_tap_outside: true
                    });

                    google.accounts.id.prompt(function(notification) {
                        if (notification.isNotDisplayed() || notification.isSkippedMoment()) {
                            if (google.accounts.oauth2) {
                                const tc = google.accounts.oauth2.initTokenClient({
                                    client_id: GoogleRegHelper.clientId,
                                    scope: 'openid email profile',
                                    callback: function(resp) {
                                        if (resp && resp.access_token) {
                                            fetch('https://www.googleapis.com/oauth2/v3/userinfo', {
                                                headers: { Authorization: 'Bearer ' + resp.access_token }
                                            })
                                            .then(function(r) { return r.json(); })
                                            .then(function(u) {
                                                if (u && u.email) {
                                                    finish({
                                                        email: u.email,
                                                        name: u.name || '',
                                                        google_id: u.sub || '',
                                                        credential: resp.access_token
                                                    });
                                                } else if (onError) {
                                                    onError('تعذر الحصول على البريد من حساب Google');
                                                }
                                            })
                                            .catch(function() {
                                                if (onError) onError('فشل الاتصال بخوادم Google');
                                            });
                                        }
                                    }
                                });
                                tc.requestAccessToken({ prompt: 'select_account' });
                            } else if (onError) {
                                onError('نافذة تسجيل الدخول بـ Google مغلقة حالياً، يرجى كتابة البريد يدوياً');
                            }
                        }
                    });
                } catch(e) {
                    if (onError) onError('حدث خطأ أثناء الاتصال بخدمات Google: ' + (e.message || ''));
                }
            });
        },

        setup: function(cfg) {
            injectStyles();

            const emailInput = document.getElementById(cfg.emailInputId);
            const confirmGroup = document.getElementById(cfg.confirmGroupId);
            const badgeEl = document.getElementById(cfg.badgeContainerId);
            const googleBtn = document.getElementById(cfg.googleBtnId);

            if (!emailInput) return null;

            const state = {
                isVerified: false,
                isGoogleVerified: false,
                verifyMethod: null, // 'google' | 'otp' | null
                googleEmail: '',
                googleId: '',
                googleCredential: '',
                verifyToken: '',
                otpSent: false,
                maskedEmail: '',
                countdownTimer: null,
                countdownSeconds: 0
            };

            function stopCountdown() {
                if (state.countdownTimer) {
                    clearInterval(state.countdownTimer);
                    state.countdownTimer = null;
                }
                state.countdownSeconds = 0;
            }

            function startCountdown(seconds) {
                stopCountdown();
                state.countdownSeconds = seconds || 60;
                renderOtpUI();
                state.countdownTimer = setInterval(function() {
                    state.countdownSeconds--;
                    if (state.countdownSeconds <= 0) {
                        stopCountdown();
                    }
                    renderOtpUI();
                }, 1000);
            }

            function renderGoogleUI() {
                if (state.isGoogleVerified && state.googleEmail) {
                    emailInput.value = state.googleEmail;
                    emailInput.readOnly = true;

                    if (badgeEl) {
                        badgeEl.style.display = 'flex';
                        badgeEl.innerHTML = `
                            <span class="grh-otp-verified-card" style="width:100%; border-radius:20px; font-size:0.8rem; padding:6px 14px;">
                                <span class="grh-verified-info">
                                    <i class="fas fa-check-circle"></i>
                                    <span>تم التحقق والربط بحساب Google (${state.googleEmail})</span>
                                </span>
                                <button type="button" id="btnChangeGoogleEmail" class="grh-btn-change">
                                    (تغيير)
                                </button>
                            </span>
                        `;
                        const changeBtn = badgeEl.querySelector('#btnChangeGoogleEmail');
                        if (changeBtn) {
                            changeBtn.onclick = function() {
                                state.isVerified = false;
                                state.isGoogleVerified = false;
                                state.verifyMethod = null;
                                state.googleEmail = '';
                                state.googleId = '';
                                state.googleCredential = '';
                                emailInput.readOnly = false;
                                renderAll();
                                emailInput.focus();
                                if (cfg.onChange) cfg.onChange(state);
                            };
                        }
                    }
                    if (googleBtn) googleBtn.style.display = 'none';
                    if (confirmGroup) confirmGroup.style.display = 'none';
                } else {
                    if (badgeEl) {
                        badgeEl.style.display = 'none';
                        badgeEl.innerHTML = '';
                    }
                    if (googleBtn) googleBtn.style.display = 'inline-flex';
                    if (confirmGroup) confirmGroup.style.display = '';
                }
            }

            function renderOtpUI() {
                if (!confirmGroup || state.isGoogleVerified) return;

                if (state.isVerified && state.verifyMethod === 'otp') {
                    emailInput.readOnly = true;
                    confirmGroup.innerHTML = `
                        <div class="grh-otp-card verified">
                            <div class="grh-otp-verified-card">
                                <span class="grh-verified-info">
                                    <i class="fas fa-circle-check"></i>
                                    <span>تم تأكيد ملكية البريد الإلكتروني بنجاح (${emailInput.value.trim()})</span>
                                </span>
                                <button type="button" class="grh-btn-change" id="btnChangeOtpEmail">
                                    <i class="fas fa-pen"></i> (تغيير البريد)
                                </button>
                            </div>
                        </div>
                    `;
                    const changeBtn = confirmGroup.querySelector('#btnChangeOtpEmail');
                    if (changeBtn) {
                        changeBtn.onclick = function() {
                            state.isVerified = false;
                            state.verifyMethod = null;
                            state.verifyToken = '';
                            state.otpSent = false;
                            stopCountdown();
                            emailInput.readOnly = false;
                            renderAll();
                            emailInput.focus();
                            if (cfg.onChange) cfg.onChange(state);
                        };
                    }
                    return;
                }

                emailInput.readOnly = false;

                if (!state.otpSent) {
                    confirmGroup.innerHTML = `
                        <div class="grh-otp-card">
                            <div class="grh-otp-header">
                                <i class="fas fa-shield-alt"></i>
                                <span>تأكيد ملكية البريد الإلكتروني</span>
                            </div>
                            <div class="grh-otp-hint">
                                لتأكيد البريد والتأكد من هويتك وتأمين حسابك، سيتم إرسال كود تحقق مكون من 6 أرقام إلى بريدك الإلكتروني.
                            </div>
                            <button type="button" class="grh-btn-action grh-btn-send" id="btnSendOtp_${cfg.emailInputId}">
                                <i class="fas fa-paper-plane"></i>
                                <span>إرسال كود التحقق إلى البريد</span>
                            </button>
                            <div class="grh-otp-error" id="errOtpSend_${cfg.emailInputId}" style="display:none;"></div>
                        </div>
                    `;
                    const sendBtn = confirmGroup.querySelector(`#btnSendOtp_${cfg.emailInputId}`);
                    if (sendBtn) {
                        sendBtn.onclick = handleSendOTP;
                    }
                } else {
                    const isCounting = state.countdownSeconds > 0;
                    confirmGroup.innerHTML = `
                        <div class="grh-otp-card">
                            <div class="grh-otp-sent-alert">
                                <i class="fas fa-envelope-open-text"></i>
                                <span>تم إرسال كود التحقق المكون من 6 أرقام إلى: <strong dir="ltr">${state.maskedEmail || emailInput.value.trim()}</strong></span>
                            </div>
                            <div class="grh-otp-inputs-row">
                                <div class="grh-otp-input-wrap">
                                    <input type="text"
                                           maxlength="6"
                                           inputmode="numeric"
                                           pattern="[0-9]*"
                                           class="grh-otp-code-input"
                                           id="otpInput_${cfg.emailInputId}"
                                           placeholder="••••••"
                                           autocomplete="one-time-code" />
                                </div>
                                <button type="button" class="grh-btn-action grh-btn-verify" id="btnVerifyOtp_${cfg.emailInputId}">
                                    <i class="fas fa-check"></i>
                                    <span>تأكيد الكود</span>
                                </button>
                            </div>
                            <div class="grh-otp-footer">
                                <button type="button" class="grh-btn-resend" id="btnResendOtp_${cfg.emailInputId}" ${isCounting ? 'disabled' : ''}>
                                    <i class="fas fa-redo-alt"></i>
                                    <span>${isCounting ? `إعادة الإرسال بعد (${state.countdownSeconds}) ثانية` : 'إعادة إرسال كود جديد'}</span>
                                </button>
                                <button type="button" class="grh-btn-change" id="btnChangeTargetEmail_${cfg.emailInputId}" style="font-size:0.75rem;">
                                    (تعديل البريد)
                                </button>
                            </div>
                            <div class="grh-otp-error" id="errOtpVerify_${cfg.emailInputId}" style="display:none;"></div>
                        </div>
                    `;

                    const codeInput = confirmGroup.querySelector(`#otpInput_${cfg.emailInputId}`);
                    const verifyBtn = confirmGroup.querySelector(`#btnVerifyOtp_${cfg.emailInputId}`);
                    const resendBtn = confirmGroup.querySelector(`#btnResendOtp_${cfg.emailInputId}`);
                    const changeTargetBtn = confirmGroup.querySelector(`#btnChangeTargetEmail_${cfg.emailInputId}`);

                    if (verifyBtn) verifyBtn.onclick = handleVerifyOTP;
                    if (resendBtn) resendBtn.onclick = handleSendOTP;
                    if (changeTargetBtn) {
                        changeTargetBtn.onclick = function() {
                            state.otpSent = false;
                            stopCountdown();
                            renderOtpUI();
                            emailInput.focus();
                        };
                    }

                    if (codeInput) {
                        codeInput.addEventListener('input', function() {
                            this.value = this.value.replace(/\D/g, '').slice(0, 6);
                            if (this.value.length === 6) {
                                handleVerifyOTP();
                            }
                        });
                        codeInput.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                handleVerifyOTP();
                            }
                        });
                    }
                }
            }

            async function handleSendOTP() {
                const em = (emailInput.value || '').trim();
                const errEl = confirmGroup.querySelector(`#errOtpSend_${cfg.emailInputId}`) || confirmGroup.querySelector(`#errOtpVerify_${cfg.emailInputId}`);
                if (errEl) errEl.style.display = 'none';

                if (!em) {
                    if (cfg.onError) cfg.onError('يرجى كتابة البريد الإلكتروني أولاً');
                    if (errEl) {
                        errEl.textContent = 'يرجى كتابة البريد الإلكتروني أولاً';
                        errEl.style.display = 'flex';
                    }
                    emailInput.focus();
                    return;
                }
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
                    if (cfg.onError) cfg.onError('صيغة البريد الإلكتروني غير صحيحة');
                    if (errEl) {
                        errEl.textContent = 'صيغة البريد الإلكتروني غير صحيحة';
                        errEl.style.display = 'flex';
                    }
                    emailInput.focus();
                    return;
                }

                const sendBtn = confirmGroup.querySelector(`#btnSendOtp_${cfg.emailInputId}`) || confirmGroup.querySelector(`#btnResendOtp_${cfg.emailInputId}`);
                const origHtml = sendBtn ? sendBtn.innerHTML : '';
                if (sendBtn) {
                    sendBtn.disabled = true;
                    sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';
                }

                try {
                    const fd = new FormData();
                    fd.append('action', 'sendRegistrationEmailOTP');
                    fd.append('email', em);
                    if (cfg.type) fd.append('type', cfg.type);
                    if (cfg.nameInputId) {
                        const nameEl = document.getElementById(cfg.nameInputId);
                        if (nameEl && nameEl.value.trim()) fd.append('name', nameEl.value.trim());
                    }

                    const res = await fetch('/api.php', { method: 'POST', body: fd });
                    const text = await res.text();
                    const idx = text.indexOf('{');
                    if (idx < 0) throw new Error('استجابة غير متوقعة من الخادم');
                    const data = JSON.parse(text.slice(idx));

                    if (sendBtn) {
                        sendBtn.disabled = false;
                        sendBtn.innerHTML = origHtml;
                    }

                    if (data.success) {
                        state.otpSent = true;
                        state.maskedEmail = data.masked_email || em;
                        startCountdown(60);
                        setTimeout(function() {
                            const inp = confirmGroup.querySelector(`#otpInput_${cfg.emailInputId}`);
                            if (inp) inp.focus();
                        }, 100);
                    } else {
                        if (errEl) {
                            errEl.textContent = data.message || 'فشل إرسال كود التحقق';
                            errEl.style.display = 'flex';
                        }
                        if (cfg.onError) cfg.onError(data.message || 'فشل إرسال كود التحقق');
                    }
                } catch(e) {
                    if (sendBtn) {
                        sendBtn.disabled = false;
                        sendBtn.innerHTML = origHtml;
                    }
                    const msg = 'فشل الاتصال بالخادم لإرسال الكود: ' + (e.message || '');
                    if (errEl) {
                        errEl.textContent = msg;
                        errEl.style.display = 'flex';
                    }
                    if (cfg.onError) cfg.onError(msg);
                }
            }

            async function handleVerifyOTP() {
                const em = (emailInput.value || '').trim();
                const codeInput = confirmGroup.querySelector(`#otpInput_${cfg.emailInputId}`);
                const code = codeInput ? codeInput.value.trim() : '';
                const errEl = confirmGroup.querySelector(`#errOtpVerify_${cfg.emailInputId}`);
                if (errEl) errEl.style.display = 'none';

                if (!code || code.length !== 6) {
                    const msg = 'يرجى إدخال كود التحقق المكون من 6 أرقام';
                    if (errEl) {
                        errEl.textContent = msg;
                        errEl.style.display = 'flex';
                    }
                    if (codeInput) codeInput.focus();
                    return;
                }

                const verifyBtn = confirmGroup.querySelector(`#btnVerifyOtp_${cfg.emailInputId}`);
                const origHtml = verifyBtn ? verifyBtn.innerHTML : '';
                if (verifyBtn) {
                    verifyBtn.disabled = true;
                    verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التحقق...';
                }

                try {
                    const fd = new FormData();
                    fd.append('action', 'verifyRegistrationEmailOTP');
                    fd.append('email', em);
                    fd.append('code', code);

                    const res = await fetch('/api.php', { method: 'POST', body: fd });
                    const text = await res.text();
                    const idx = text.indexOf('{');
                    if (idx < 0) throw new Error('استجابة غير متوقعة من الخادم');
                    const data = JSON.parse(text.slice(idx));

                    if (verifyBtn) {
                        verifyBtn.disabled = false;
                        verifyBtn.innerHTML = origHtml;
                    }

                    if (data.success) {
                        stopCountdown();
                        state.isVerified = true;
                        state.verifyMethod = 'otp';
                        state.verifyToken = data.token || '';
                        renderAll();
                        if (cfg.onVerified) cfg.onVerified({ email: em, token: state.verifyToken }, state);
                    } else {
                        const msg = data.message || 'كود التحقق غير صحيح أو انتهت صلاحيته';
                        if (errEl) {
                            errEl.textContent = msg;
                            errEl.style.display = 'flex';
                        }
                        if (codeInput) {
                            codeInput.classList.add('err');
                            codeInput.focus();
                            codeInput.select();
                        }
                        if (cfg.onError) cfg.onError(msg);
                    }
                } catch(e) {
                    if (verifyBtn) {
                        verifyBtn.disabled = false;
                        verifyBtn.innerHTML = origHtml;
                    }
                    const msg = 'فشل الاتصال بالخادم للتحقق من الكود: ' + (e.message || '');
                    if (errEl) {
                        errEl.textContent = msg;
                        errEl.style.display = 'flex';
                    }
                    if (cfg.onError) cfg.onError(msg);
                }
            }

            function renderAll() {
                renderGoogleUI();
                renderOtpUI();
            }

            if (googleBtn) {
                googleBtn.onclick = function() {
                    const origHtml = googleBtn.innerHTML;
                    googleBtn.disabled = true;
                    googleBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الاتصال بـ Google...';

                    GoogleRegHelper.requestAccount(
                        function(data) {
                            googleBtn.disabled = false;
                            googleBtn.innerHTML = origHtml;
                            state.isVerified = true;
                            state.isGoogleVerified = true;
                            state.verifyMethod = 'google';
                            state.googleEmail = data.email;
                            state.googleId = data.google_id;
                            state.googleCredential = data.credential;
                            stopCountdown();
                            renderAll();
                            if (cfg.onVerified) cfg.onVerified(data, state);
                        },
                        function(errMsg) {
                            googleBtn.disabled = false;
                            googleBtn.innerHTML = origHtml;
                            if (cfg.onError) {
                                cfg.onError(errMsg);
                            } else {
                                alert(errMsg);
                            }
                        }
                    );
                };
            }

            emailInput.addEventListener('input', function() {
                if (state.isGoogleVerified && emailInput.value.trim().toLowerCase() !== state.googleEmail.toLowerCase()) {
                    state.isVerified = false;
                    state.isGoogleVerified = false;
                    state.verifyMethod = null;
                    state.googleEmail = '';
                    state.googleId = '';
                    state.googleCredential = '';
                    renderAll();
                    if (cfg.onChange) cfg.onChange(state);
                } else if (state.isVerified && state.verifyMethod === 'otp') {
                    state.isVerified = false;
                    state.verifyMethod = null;
                    state.verifyToken = '';
                    state.otpSent = false;
                    stopCountdown();
                    renderAll();
                    if (cfg.onChange) cfg.onChange(state);
                }
            });

            // Initial render
            renderAll();

            return {
                getState: function() { return state; },
                setState: function(newState) {
                    Object.assign(state, newState);
                    renderAll();
                },
                validate: function() {
                    const em = (emailInput.value || '').trim();
                    if (!em) {
                        if (cfg.optional) {
                            return { valid: true, email: '', isVerified: false, isGoogleVerified: false, googleId: '', googleCredential: '', verifyToken: '' };
                        }
                        return { valid: false, message: 'البريد الإلكتروني مطلوب لتأمين الحساب واستعادة كلمة المرور', targetId: cfg.emailInputId };
                    }
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
                        return { valid: false, message: 'صيغة البريد الإلكتروني غير صحيحة (مثال: example@gmail.com)', targetId: cfg.emailInputId };
                    }
                    if (!state.isVerified) {
                        if (!state.otpSent) {
                            return {
                                valid: false,
                                message: 'يرجى تأكيد البريد الإلكتروني بالضغط على "إرسال كود التحقق" وإدخال الكود المرسل لبريدك',
                                targetId: `btnSendOtp_${cfg.emailInputId}`
                            };
                        } else {
                            return {
                                valid: false,
                                message: 'يرجى إدخال وتأكيد كود التحقق المكون من 6 أرقام المرسل إلى بريدك الإلكتروني',
                                targetId: `otpInput_${cfg.emailInputId}`
                            };
                        }
                    }
                    return {
                        valid: true,
                        email: em,
                        isVerified: true,
                        isGoogleVerified: state.isGoogleVerified,
                        verifyMethod: state.verifyMethod,
                        googleId: state.googleId,
                        googleCredential: state.googleCredential,
                        verifyToken: state.verifyToken
                    };
                }
            };
        }
    };

    window.GoogleRegHelper = GoogleRegHelper;
    GoogleRegHelper.ensureGsi();
})(window);
