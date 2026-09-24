/**
 * SessionManager - Production Session Management with Refresh Token Rotation (RTR)
 * Sunday School System
 *
 * Features:
 * - 15-minute Access Token management with silent background renewal
 * - Concurrency Mutex (Promise Queue) to prevent multiple simultaneous refresh calls
 * - Handles 401 Unauthorized with automatic transparent retry
 * - Clean user notifications without exposing internal system details and without emojis
 */

(function (window) {
    'use strict';

    const API_ENDPOINT = (window.API_URL || '/api.php');
    const ACCESS_TOKEN_KEY = 'ss_access_token';
    const EXPIRES_AT_KEY = 'ss_token_expires_at';
    const PROACTIVE_REFRESH_WINDOW = 60 * 1000; // Refresh 60s before expiry

    let inMemoryToken = null;
    let refreshPromise = null; // Mutex for concurrent refresh calls
    let refreshTimer = null;

    const SessionManager = {
        /**
         * Initialize SessionManager with existing stored access token if any.
         */
        init() {
            inMemoryToken = sessionStorage.getItem(ACCESS_TOKEN_KEY) || localStorage.getItem(ACCESS_TOKEN_KEY) || localStorage.getItem('authToken');
            this.scheduleProactiveRefresh();

            // Listen for storage events across tabs
            window.addEventListener('storage', (e) => {
                if (e.key === ACCESS_TOKEN_KEY && e.newValue) {
                    inMemoryToken = e.newValue;
                    this.scheduleProactiveRefresh();
                } else if (e.key === 'ss_logout_event') {
                    this.clearSession(false);
                }
            });
        },

        /**
         * Get the current Access Token.
         */
        getAccessToken() {
            if (!inMemoryToken) {
                inMemoryToken = sessionStorage.getItem(ACCESS_TOKEN_KEY) || localStorage.getItem(ACCESS_TOKEN_KEY) || localStorage.getItem('authToken');
            }
            return inMemoryToken;
        },

        /**
         * Save newly issued Access Token and expiration.
         */
        setAccessToken(token, expiresInSeconds) {
            inMemoryToken = token;
            const expiresIn = expiresInSeconds || 900; // Default 15 minutes
            const expiresAt = Date.now() + (expiresIn * 1000);

            try {
                sessionStorage.setItem(ACCESS_TOKEN_KEY, token);
                sessionStorage.setItem(EXPIRES_AT_KEY, String(expiresAt));
                localStorage.setItem(ACCESS_TOKEN_KEY, token);
                localStorage.setItem('authToken', token); // Backward compatibility
                localStorage.setItem(EXPIRES_AT_KEY, String(expiresAt));
            } catch (e) {
                // Storage full or restricted in private mode
            }

            this.scheduleProactiveRefresh();
        },

        /**
         * Check if the current Access Token is expired or about to expire.
         */
        isTokenExpiringSoon() {
            const exp = parseInt(sessionStorage.getItem(EXPIRES_AT_KEY) || localStorage.getItem(EXPIRES_AT_KEY) || '0', 10);
            if (!exp) return false;
            return Date.now() >= (exp - PROACTIVE_REFRESH_WINDOW);
        },

        /**
         * Schedule silent proactive refresh before the 15-minute token expires.
         */
        scheduleProactiveRefresh() {
            if (refreshTimer) {
                clearTimeout(refreshTimer);
                refreshTimer = null;
            }

            const exp = parseInt(sessionStorage.getItem(EXPIRES_AT_KEY) || localStorage.getItem(EXPIRES_AT_KEY) || '0', 10);
            if (!exp) return;

            const delay = Math.max(1000, exp - Date.now() - PROACTIVE_REFRESH_WINDOW);
            refreshTimer = setTimeout(() => {
                this.refreshToken().catch(() => {});
            }, delay);
        },

        /**
         * Request a new Access Token & Refresh Token pair via Refresh Token Rotation.
         * Thread-safe with Mutex / Promise queuing for concurrent requests.
         */
        async refreshToken() {
            // If already refreshing, return the in-flight Promise (Concurrency Mutex)
            if (refreshPromise) {
                return refreshPromise;
            }

            refreshPromise = (async () => {
                try {
                    const fd = new FormData();
                    fd.append('action', 'refresh_token');

                    const response = await fetch(API_ENDPOINT, {
                        method: 'POST',
                        body: fd,
                        credentials: 'include' // Sends the HttpOnly, Secure ss_refresh_token Cookie
                    });

                    const data = await response.json();

                    if (data && data.success && data.access_token) {
                        this.setAccessToken(data.access_token, data.expires_in || 900);
                        return data.access_token;
                    }

                    // Handle Security Violations or Expirations
                    if (data && (data.error === 'TOKEN_THEFT_DETECTED' || data.error === 'SESSION_EXPIRED')) {
                        this.handleSessionEnded(data.message);
                        throw new Error(data.error);
                    }

                    throw new Error(data?.message || 'Token refresh failed');
                } finally {
                    refreshPromise = null;
                }
            })();

            return refreshPromise;
        },

        /**
         * Authenticated Fetch Wrapper:
         * Automatically ensures a fresh token is attached and retries on 401.
         */
        async fetch(url, options = {}) {
            options = Object.assign({}, options);
            options.credentials = options.credentials || 'include';

            // If token is expiring soon, refresh it before dispatching
            if (this.isTokenExpiringSoon()) {
                try {
                    await this.refreshToken();
                } catch (e) {
                    // Refresh failed, proceed with current token or retry on 401
                }
            }

            const token = this.getAccessToken();
            options.headers = options.headers || {};
            if (token) {
                if (options.headers instanceof Headers) {
                    if (!options.headers.has('Authorization')) {
                        options.headers.set('Authorization', `Bearer ${token}`);
                    }
                } else if (Array.isArray(options.headers)) {
                    options.headers.push(['Authorization', `Bearer ${token}`]);
                } else {
                    options.headers['Authorization'] = options.headers['Authorization'] || `Bearer ${token}`;
                }
            }

            let response;
            try {
                response = await fetch(url, options);
            } catch (err) {
                throw err;
            }

            // If 401 Unauthorized, attempt transparent token refresh once
            if (response.status === 401) {
                try {
                    const newToken = await this.refreshToken();
                    if (newToken) {
                        // Clone options and update authorization
                        const retryOptions = Object.assign({}, options);
                        if (retryOptions.headers instanceof Headers) {
                            retryOptions.headers.set('Authorization', `Bearer ${newToken}`);
                        } else if (typeof retryOptions.headers === 'object') {
                            retryOptions.headers['Authorization'] = `Bearer ${newToken}`;
                        }
                        return await fetch(url, retryOptions);
                    }
                } catch (refreshErr) {
                    // Refresh failed; return original 401 response
                    return response;
                }
            }

            return response;
        },

        /**
         * Handle Session Termination (Standard message without emojis).
         */
        handleSessionEnded(message) {
            this.clearSession(true);
            const msg = message || 'انتهت الجلسة، الرجاء تسجيل الدخول مجددا';
            alert(msg);
            window.location.href = '/login/';
        },

        /**
         * Clear stored tokens and all auth state locally.
         */
        clearSession(broadcast = true) {
            inMemoryToken = null;
            if (refreshTimer) {
                clearTimeout(refreshTimer);
                refreshTimer = null;
            }
            try {
                // Wipe token keys
                sessionStorage.removeItem(ACCESS_TOKEN_KEY);
                sessionStorage.removeItem(EXPIRES_AT_KEY);
                localStorage.removeItem(ACCESS_TOKEN_KEY);
                localStorage.removeItem(EXPIRES_AT_KEY);
                localStorage.removeItem('authToken');
                localStorage.removeItem('auth_token');

                // Wipe all user identity & session state flags (preserve UI preferences like theme)
                const authKeys = [
                    'loggedIn', 'uncleLoggedIn', 'loginType',
                    'churchCode', 'church_code', 'churchName',
                    'churchId', 'church_id', 'churchType',
                    'adminEmail', 'admin_email',
                    'uncleId', 'uncle_id', 'uncleName',
                    'uncleRole', 'role', 'uncleImage', 'uncleUsername',
                    'unclePassword', 'savedPassword', 'savedUsername',
                    'rememberMe', 'userPhone', 'isDeveloper', 'devViewChurchId',
                    'assignedClasses', 'lastStudentsData', 'churchSettings',
                    'currentClass', 'lastVisitedPortal', 'activeKidAccountId',
                    '_loginRestoreAttempted', '_ss_restoring', 'justLoggedInMultiAccounts'
                ];
                authKeys.forEach(k => {
                    try { localStorage.removeItem(k); } catch (e) {}
                    try { sessionStorage.removeItem(k); } catch (e) {}
                });

                // Clear cached trip & exam answer temporary data
                try {
                    Object.keys(localStorage).forEach(k => {
                        if (k.startsWith('ss_cached_trip_') || k.startsWith('ta_')) {
                            localStorage.removeItem(k);
                        }
                    });
                } catch (e) {}

                // Reset per-tab sessionStorage
                try { sessionStorage.clear(); } catch (e) {}

                if (broadcast) {
                    localStorage.setItem('ss_logout_event', String(Date.now()));
                }
            } catch (e) {}
        },

        /**
         * Perform full user logout:
         * Informs backend to revoke all family tokens, deletes cookie, and clears storage.
         */
        async logout(redirectUrl = '/login/') {
            try {
                const fd = new FormData();
                fd.append('action', 'logout');
                const token = this.getAccessToken();
                if (token) {
                    fd.append('access_token', token);
                }
                await fetch(API_ENDPOINT, {
                    method: 'POST',
                    body: fd,
                    credentials: 'include'
                });
            } catch (e) {
                // Ignore network failure on logout
            } finally {
                this.clearSession(true);
                window.location.href = redirectUrl;
            }
        }
    };

    // Auto-init on page load
    SessionManager.init();

    window.SessionManager = SessionManager;
})(window);
