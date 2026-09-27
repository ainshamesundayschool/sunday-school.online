/**
 * Google Registration & Email Verification Helper
 * Provides universal Google Identity linking, email autofill, and manual email confirmation
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
            const emailInput = document.getElementById(cfg.emailInputId);
            const confirmInput = document.getElementById(cfg.confirmInputId);
            const confirmGroup = document.getElementById(cfg.confirmGroupId);
            const badgeEl = document.getElementById(cfg.badgeContainerId);
            const googleBtn = document.getElementById(cfg.googleBtnId);

            if (!emailInput) return null;

            const state = {
                isGoogleVerified: false,
                googleEmail: '',
                googleId: '',
                googleCredential: ''
            };

            function renderState() {
                if (state.isGoogleVerified && state.googleEmail) {
                    emailInput.value = state.googleEmail;
                    if (confirmInput) confirmInput.value = state.googleEmail;
                    if (confirmGroup) confirmGroup.style.display = 'none';

                    if (badgeEl) {
                        badgeEl.style.display = 'flex';
                        badgeEl.innerHTML = `
                            <span style="display:inline-flex; align-items:center; gap:6px; background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:5px 12px; border-radius:20px; font-size:0.8rem; font-weight:700;">
                                <i class="fas fa-check-circle"></i>
                                <span>تم التحقق والربط بحساب Google</span>
                                <button type="button" id="btnChangeGoogleEmail" style="background:none; border:none; color:#166534; font-weight:800; font-size:0.75rem; text-decoration:underline; cursor:pointer; margin-right:6px; font-family:inherit;">
                                    (تغيير)
                                </button>
                            </span>
                        `;
                        const changeBtn = badgeEl.querySelector('#btnChangeGoogleEmail');
                        if (changeBtn) {
                            changeBtn.onclick = function() {
                                state.isGoogleVerified = false;
                                state.googleEmail = '';
                                state.googleId = '';
                                state.googleCredential = '';
                                renderState();
                                emailInput.focus();
                                if (cfg.onChange) cfg.onChange(state);
                            };
                        }
                    }
                    if (googleBtn) {
                        googleBtn.style.display = 'none';
                    }
                } else {
                    if (confirmGroup) confirmGroup.style.display = 'block';
                    if (badgeEl) {
                        badgeEl.style.display = 'none';
                        badgeEl.innerHTML = '';
                    }
                    if (googleBtn) {
                        googleBtn.style.display = 'inline-flex';
                    }
                }
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
                            state.isGoogleVerified = true;
                            state.googleEmail = data.email;
                            state.googleId = data.google_id;
                            state.googleCredential = data.credential;
                            renderState();
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
                    state.isGoogleVerified = false;
                    state.googleEmail = '';
                    state.googleId = '';
                    state.googleCredential = '';
                    renderState();
                    if (cfg.onChange) cfg.onChange(state);
                }
            });

            // Initialize UI
            renderState();

            return {
                getState: function() { return state; },
                setState: function(newState) {
                    Object.assign(state, newState);
                    renderState();
                },
                validate: function() {
                    const em = (emailInput.value || '').trim();
                    if (!em) {
                        return { valid: false, message: 'البريد الإلكتروني مطلوب لتأمين الحساب واستعادة كلمة المرور', targetId: cfg.emailInputId };
                    }
                    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
                        return { valid: false, message: 'صيغة البريد الإلكتروني غير صحيحة (مثال: example@gmail.com)', targetId: cfg.emailInputId };
                    }
                    if (!state.isGoogleVerified) {
                        const emConfirm = confirmInput ? (confirmInput.value || '').trim() : '';
                        if (!emConfirm) {
                            return { valid: false, message: 'يرجى تأكيد البريد الإلكتروني لضمان استقبال رموز الاستعادة', targetId: cfg.confirmInputId };
                        }
                        if (em.toLowerCase() !== emConfirm.toLowerCase()) {
                            return { valid: false, message: 'البريد الإلكتروني وتأكيد البريد غير متطابقين، يرجى التأكد لتلقي رموز الاستعادة', targetId: cfg.confirmInputId };
                        }
                    }
                    return { valid: true, email: em, isGoogleVerified: state.isGoogleVerified, googleId: state.googleId, googleCredential: state.googleCredential };
                }
            };
        }
    };

    window.GoogleRegHelper = GoogleRegHelper;
    GoogleRegHelper.ensureGsi();
})(window);
