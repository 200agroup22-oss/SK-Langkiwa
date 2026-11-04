<?php
// Toast notifications for the whole system. Include this once per page (the admin sidebar, the applicant / scholar
// navigation bar and the login pages already do).
//
// 1. Every page-level success or error message that the server renders as a green or red alert
//    (<div class="alert alert-success py-2"> / <div class="alert alert-danger py-2">, e.g. "Applicant added.",
//    "Program archived.", "Account created successfully!") is turned into a toast automatically when the page loads.
// 2. Scripts can show their own with showToast('Saved.', 'success') or showToast('Could not save.', 'error').
//
// Left as they are on purpose: alerts inside a modal, decline-reason boxes, the login lock countdown, and any
// alert marked data-keep.
if (!defined('SK_TOAST_LOADED')) {
    define('SK_TOAST_LOADED', true);
?>
    <style>
        #toastStack {
            position: fixed;
            top: 70px;
            right: 16px;
            z-index: 20000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: min(380px, calc(100vw - 32px));
            pointer-events: none;
        }

        .sk-toast {
            pointer-events: auto;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 14px 14px;
            background: #fff;
            color: #222;
            font-family: inherit;
            font-size: 13.5px;
            line-height: 1.4;
            border-radius: 10px;
            border-left: 5px solid #45b84d;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
            animation: skToastIn 0.25s ease;
        }

        .sk-toast.error {
            border-left-color: #e53935;
        }

        .sk-toast.leaving {
            animation: skToastOut 0.2s ease forwards;
        }

        .sk-toast .t-icon {
            font-size: 20px;
            line-height: 1;
            color: #45b84d;
            flex: 0 0 auto;
        }

        .sk-toast.error .t-icon {
            color: #e53935;
        }

        .sk-toast .t-body {
            flex: 1 1 auto;
            min-width: 0;
            word-break: break-word;
            white-space: pre-line;
        }

        .sk-toast .t-title {
            font-weight: 700;
            margin-bottom: 1px;
        }

        .sk-toast .t-close {
            flex: 0 0 auto;
            border: 0;
            background: none;
            color: #888;
            font-size: 20px;
            line-height: 1;
            padding: 0 2px;
            cursor: pointer;
        }

        .sk-toast .t-close:hover {
            color: #222;
        }

        .sk-toast .t-bar {
            position: absolute;
            left: 0;
            bottom: 0;
            height: 3px;
            width: 100%;
            background: #45b84d;
            transform-origin: left;
            animation: skToastBar linear forwards;
        }

        .sk-toast.error .t-bar {
            background: #e53935;
        }

        .sk-toast:hover .t-bar {
            animation-play-state: paused;
        }

        @keyframes skToastIn {
            from {
                opacity: 0;
                transform: translateX(30px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes skToastOut {
            to {
                opacity: 0;
                transform: translateX(30px);
            }
        }

        @keyframes skToastBar {
            from {
                transform: scaleX(1);
            }

            to {
                transform: scaleX(0);
            }
        }

        @media (max-width: 576px) {
            #toastStack {
                top: 64px;
                right: 10px;
                left: 10px;
                width: auto;
            }
        }
    </style>
    <script>
        (function() {
            function stack() {
                var s = document.getElementById('toastStack');
                if (!s) {
                    s = document.createElement('div');
                    s.id = 'toastStack';
                    s.setAttribute('aria-live', 'polite');
                    document.body.appendChild(s);
                }
                return s;
            }

            // showToast('Applicant added.', 'success')  /  showToast('Could not save.', 'error')
            window.showToast = function(message, type) {
                type = type === 'error' ? 'error' : 'success';
                message = String(message || '').trim();
                if (!message) return;
                var toast = document.createElement('div');
                toast.className = 'sk-toast ' + type;
                toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

                var icon = document.createElement('i');
                icon.className = 't-icon bi ' + (type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
                var body = document.createElement('div');
                body.className = 't-body';
                var title = document.createElement('div');
                title.className = 't-title';
                title.textContent = type === 'error' ? 'Error' : 'Success';
                var text = document.createElement('div');
                text.textContent = message;
                body.appendChild(title);
                body.appendChild(text);
                var close = document.createElement('button');
                close.type = 'button';
                close.className = 't-close';
                close.setAttribute('aria-label', 'Close');
                close.innerHTML = '&times;';
                var bar = document.createElement('div');
                bar.className = 't-bar';
                // longer messages stay a little longer; errors stay longer than successes
                var ms = Math.min(14000, (type === 'error' ? 6000 : 4000) + message.length * 35);
                bar.style.animationDuration = ms + 'ms';

                function remove() {
                    if (!toast.parentNode) return;
                    toast.classList.add('leaving');
                    setTimeout(function() {
                        if (toast.parentNode) toast.parentNode.removeChild(toast);
                    }, 200);
                }
                close.addEventListener('click', remove);
                bar.addEventListener('animationend', remove);

                toast.appendChild(icon);
                toast.appendChild(body);
                toast.appendChild(close);
                toast.appendChild(bar);
                stack().appendChild(toast);
            };

            // Turn the server-rendered flash alerts into toasts.
            function convertAlerts() {
                var alerts = document.querySelectorAll('.alert.alert-success.py-2, .alert.alert-danger.py-2');
                Array.prototype.forEach.call(alerts, function(el) {
                    if (el.closest('.modal') || el.hasAttribute('data-keep')) return;
                    if (el.querySelector('#lockCountdown')) return; // the login lock keeps its countdown on screen
                    var text = (el.innerText || el.textContent || '').trim();
                    if (!text || /^reason:/i.test(text)) return; // decline reasons are information, not a notice
                    window.showToast(text, el.classList.contains('alert-danger') ? 'error' : 'success');
                    el.parentNode.removeChild(el);
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', convertAlerts);
            } else {
                convertAlerts();
            }
        })();
    </script>
<?php } ?>