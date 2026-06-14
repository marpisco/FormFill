<?php
/**
 * FormFill CSRF Protection
 * 
 * Per-session CSRF tokens using 64-char hex strings and hash_equals verification.
 * Includes a global JavaScript injector that auto-appends the token to all POST forms.
 */

namespace FormFill\Lib;

class Csrf
{
    /**
     * Generate or retrieve the current CSRF token.
     * Creates one if it doesn't exist in the session.
     */
    public static function generate(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Force a new CSRF token. Call on authentication state changes
     * (login, logout) to prevent token reuse across privilege boundaries.
     */
    public static function regenerate(): string
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify a submitted CSRF token against the session token.
     * Uses hash_equals for timing-attack-safe comparison.
     */
    public static function verify(string $token): bool
    {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate a hidden input field containing the CSRF token.
     */
    public static function field(): string
    {
        $token = self::generate();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * JavaScript snippet that auto-injects CSRF tokens into all POST forms.
     * Place this in the <head> or before </body> of any page with forms.
     * 
     * Also exposes window.__submitFormWithCsrf(form) for programmatic form submission.
     */
    public static function globalInjector(): string
    {
        $token = htmlspecialchars(self::generate(), ENT_QUOTES, 'UTF-8');

        return "
        <script>
        (function() {
            function ensureCsrf(form) {
                if (!form || String(form.method).toLowerCase() !== 'post') return;
                if (!form.querySelector('input[name=\"csrf_token\"]')) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'csrf_token';
                    input.value = '{$token}';
                    form.appendChild(input);
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('form[method=\"POST\"], form[method=\"post\"]').forEach(ensureCsrf);
            });

            document.addEventListener('submit', function(event) {
                ensureCsrf(event.target);
            }, true);

            window.__submitFormWithCsrf = function(form) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    ensureCsrf(form);
                    form.submit();
                }
            };
        })();
        </script>";
    }
}
