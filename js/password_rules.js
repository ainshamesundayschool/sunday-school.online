/**
 * Password Rules & Strength Meter Utility
 * Sunday School Platform
 * Enforces: Uppercase, Lowercase, Numbers, Minimum 6 characters (recommended 8+), not default 123456.
 */

(function (root, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.PasswordRules = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const PasswordRules = {
        /**
         * Check password complexity
         * @param {string} password 
         * @returns {Object}
         */
        check(password) {
            const pwd = password || '';
            const hasMinLength = pwd.length >= 6;
            const hasLower = /[a-z]/.test(pwd);
            const hasUpper = /[A-Z]/.test(pwd);
            const hasNumber = /[0-9]/.test(pwd);
            const isNotDefault = pwd !== '123456' && pwd.toLowerCase() !== 'password';

            // Strength calculation
            let score = 0;
            if (hasMinLength) score++;
            if (hasLower) score++;
            if (hasUpper) score++;
            if (hasNumber) score++;
            if (pwd.length >= 8) score++;

            // Strict validity requires: length >= 6, lowercase, uppercase, number, not default
            const isValid = hasMinLength && hasLower && hasUpper && hasNumber && isNotDefault;

            let strength = 'weak';
            let label = 'ضعيفة';
            let color = 'var(--danger, #ef4444)';
            let percent = 20;

            if (score <= 2 || !hasMinLength) {
                strength = 'weak';
                label = 'ضعيفة';
                color = 'var(--danger, #ef4444)';
                percent = Math.max(15, score * 20);
            } else if (score < 5 || !isValid) {
                strength = 'medium';
                label = 'متوسطة';
                color = 'var(--warning, #f59e0b)';
                percent = 65;
            } else {
                strength = 'strong';
                label = 'قوية ومحمية';
                color = 'var(--success, #10b981)';
                percent = 100;
            }

            const errors = [];
            if (!hasMinLength) errors.push('يجب أن لا تقل عن 6 أحرف أو أرقام');
            if (!hasUpper) errors.push('يجب أن تحتوي على حرف كبير إنجليزي واحد على الأقل (A-Z)');
            if (!hasLower) errors.push('يجب أن تحتوي على حرف صغير إنجليزي واحد على الأقل (a-z)');
            if (!hasNumber) errors.push('يجب أن تحتوي على رقم واحد على الأقل (0-9)');
            if (!isNotDefault) errors.push('لا يمكنك استخدام كلمة مرور افتراضية شائعة');

            return {
                isValid,
                score,
                strength,
                label,
                color,
                percent,
                hasMinLength,
                hasLower,
                hasUpper,
                hasNumber,
                isNotDefault,
                errors
            };
        },

        /**
         * Attach real-time strength meter under a password input element
         * @param {string|HTMLInputElement} inputElOrId
         * @param {Object} options
         */
        attach(inputElOrId, options = {}) {
            const input = typeof inputElOrId === 'string' ? document.getElementById(inputElOrId) : inputElOrId;
            if (!input) return null;

            // Remove previous meter if any
            const existingMeter = input.parentElement?.parentElement?.querySelector(`.pwd-strength-container[data-for="${input.id}"]`);
            if (existingMeter) existingMeter.remove();

            const container = document.createElement('div');
            container.className = 'pwd-strength-container';
            container.setAttribute('data-for', input.id || 'pwd');
            container.style.cssText = `
                margin-top: 8px;
                margin-bottom: 12px;
                font-family: 'Cairo', sans-serif;
                direction: rtl;
                text-align: right;
                display: none;
                transition: all 0.3s ease;
            `;

            container.innerHTML = `
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:5px; font-size:0.78rem;">
                    <span style="color:var(--text-3, #8b90a8); font-weight:600;">قوة كلمة المرور:</span>
                    <span class="pwd-strength-text" style="font-weight:800; color:var(--danger, #ef4444);">ضعيفة</span>
                </div>
                <div style="width:100%; height:5px; background:rgba(0,0,0,0.08); border-radius:10px; overflow:hidden; margin-bottom:8px;">
                    <div class="pwd-strength-bar" style="height:100%; width:0%; background:var(--danger, #ef4444); border-radius:10px; transition:width 0.3s ease, background-color 0.3s ease;"></div>
                </div>
                <div class="pwd-rules-list" style="display:grid; grid-template-columns:repeat(2, 1fr); gap:4px; font-size:0.74rem;">
                    <div class="rule-item rule-upper" style="color:var(--text-3, #94a3b8); display:flex; align-items:center; gap:5px;">
                        <i class="fas fa-times-circle" style="font-size:0.8rem; color:var(--danger, #ef4444);"></i>
                        <span>حرف كبير (A-Z)</span>
                    </div>
                    <div class="rule-item rule-lower" style="color:var(--text-3, #94a3b8); display:flex; align-items:center; gap:5px;">
                        <i class="fas fa-times-circle" style="font-size:0.8rem; color:var(--danger, #ef4444);"></i>
                        <span>حرف صغير (a-z)</span>
                    </div>
                    <div class="rule-item rule-num" style="color:var(--text-3, #94a3b8); display:flex; align-items:center; gap:5px;">
                        <i class="fas fa-times-circle" style="font-size:0.8rem; color:var(--danger, #ef4444);"></i>
                        <span>أرقام (0-9)</span>
                    </div>
                    <div class="rule-item rule-min" style="color:var(--text-3, #94a3b8); display:flex; align-items:center; gap:5px;">
                        <i class="fas fa-times-circle" style="font-size:0.8rem; color:var(--danger, #ef4444);"></i>
                        <span>6 خانات فأكثر</span>
                    </div>
                </div>
            `;

            // Insert container after input container
            const parent = input.closest('.form-group') || input.parentElement;
            if (parent && parent.nextSibling) {
                parent.parentNode.insertBefore(container, parent.nextSibling);
            } else if (parent) {
                parent.appendChild(container);
            }

            const bar = container.querySelector('.pwd-strength-bar');
            const text = container.querySelector('.pwd-strength-text');
            const rUpper = container.querySelector('.rule-upper');
            const rLower = container.querySelector('.rule-lower');
            const rNum = container.querySelector('.rule-num');
            const rMin = container.querySelector('.rule-min');

            function updateRule(el, ok) {
                if (!el) return;
                const icon = el.querySelector('i');
                if (ok) {
                    el.style.color = 'var(--success-dark, #059669)';
                    if (icon) {
                        icon.className = 'fas fa-check-circle';
                        icon.style.color = 'var(--success, #10b981)';
                    }
                } else {
                    el.style.color = 'var(--text-3, #94a3b8)';
                    if (icon) {
                        icon.className = 'fas fa-times-circle';
                        icon.style.color = 'var(--danger, #ef4444)';
                    }
                }
            }

            function onInput() {
                const val = input.value;
                if (!val) {
                    container.style.display = 'none';
                    return;
                }
                container.style.display = 'block';
                const res = PasswordRules.check(val);

                bar.style.width = res.percent + '%';
                bar.style.backgroundColor = res.color;
                text.textContent = res.label;
                text.style.color = res.color;

                updateRule(rUpper, res.hasUpper);
                updateRule(rLower, res.hasLower);
                updateRule(rNum, res.hasNumber);
                updateRule(rMin, res.hasMinLength);

                if (typeof options.onChange === 'function') {
                    options.onChange(res);
                }
            }

            input.addEventListener('input', onInput);
            input.addEventListener('focus', onInput);

            return {
                check: () => PasswordRules.check(input.value),
                container
            };
        }
    };

    return PasswordRules;
}));
