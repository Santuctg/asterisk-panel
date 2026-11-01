/* =========================================================================
   Asterisk Panel — front-end behaviour
   Vanilla JS, no dependencies. Handles: sidebar, dropdowns, flash dismissal,
   modal open/close, confirm-on-delete, copy-to-clipboard, dynamic form rows,
   and live search filtering.
   ========================================================================= */
(function () {
    'use strict';

    // ---- Sidebar toggle (mobile) ----------------------------------------
    var shell = document.querySelector('.app-shell');
    var toggle = document.getElementById('sidebarToggle');
    if (toggle && shell) {
        toggle.addEventListener('click', function () {
            shell.classList.toggle('sidebar-open');
        });
        // Close the drawer when clicking the main area on small screens.
        document.addEventListener('click', function (e) {
            if (!shell.classList.contains('sidebar-open')) return;
            if (shell.contains(e.target)) return;
            shell.classList.remove('sidebar-open');
        });
    }

    // ---- User dropdown ---------------------------------------------------
    var userBtn = document.getElementById('userMenuBtn');
    var userMenu = document.getElementById('userMenu');
    if (userBtn && userMenu) {
        userBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !userMenu.hidden;
            userMenu.hidden = open;
            userBtn.setAttribute('aria-expanded', String(!open));
        });
        document.addEventListener('click', function (e) {
            if (userMenu.hidden) return;
            if (userMenu.contains(e.target) || userBtn.contains(e.target)) return;
            userMenu.hidden = true;
            userBtn.setAttribute('aria-expanded', 'false');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !userMenu.hidden) {
                userMenu.hidden = true;
                userBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // ---- Flash auto-dismiss ----------------------------------------------
    document.querySelectorAll('[data-flash]').forEach(function (el) {
        var close = el.querySelector('.flash-close');
        if (close) {
            close.addEventListener('click', function () { el.remove(); });
        }
        // Errors stay put; success/info fade after a few seconds.
        if (el.classList.contains('flash-success') || el.classList.contains('flash-info')) {
            setTimeout(function () {
                el.style.transition = 'opacity .35s, transform .35s';
                el.style.opacity = '0';
                el.style.transform = 'translateY(-6px)';
                setTimeout(function () { el.remove(); }, 380);
            }, 4200);
        }
    });

    // ---- Confirm dangerous actions ---------------------------------------
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.confirm) {
            if (!window.confirm(form.dataset.confirm)) {
                e.preventDefault();
            }
        }
    });

    // Also support data-confirm on plain buttons that submit a form.
    document.querySelectorAll('[data-confirm-btn]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (!window.confirm(btn.getAttribute('data-confirm-btn'))) {
                e.preventDefault();
            }
        });
    });

    // ---- Copy to clipboard ------------------------------------------------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-copy]');
        if (!btn) return;
        e.preventDefault();

        var value = btn.getAttribute('data-copy');
        if (!value) {
            var src = document.getElementById(btn.getAttribute('data-copy-target') || '');
            value = src ? src.value : '';
        }

        var done = function () {
            var original = btn.innerHTML;
            btn.innerHTML = '✓ Copied';
            btn.classList.add('is-copied');
            setTimeout(function () {
                btn.innerHTML = original;
                btn.classList.remove('is-copied');
            }, 1600);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done).catch(fallback);
        } else {
            fallback();
        }

        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = value;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (err) { /* no-op */ }
            document.body.removeChild(ta);
        }
    });

    // ---- Live table filtering ---------------------------------------------
    var search = document.getElementById('globalSearch');
    if (search) {
        search.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var q = search.value.trim();
            // Jump to the most relevant section based on keyword hints.
            var map = [
                [/ext|sip|user/i, '/extensions'],
                [/dev|mac|phone|prov/i, '/devices'],
                [/queue|call ?cent|agent/i, '/call-center'],
                [/voicemail|mail/i, '/voicemail'],
                [/ivr|menu/i, '/ivr'],
                [/ring|group/i, '/ring-groups'],
                [/conf|meet/i, '/conferences'],
                [/route|trunk|did/i, '/routes'],
                [/park/i, '/parking'],
                [/music|moh/i, '/moh'],
                [/cdr|call history|history/i, '/cdr'],
                [/tenant|business/i, '/tenants'],
                [/user|account|login/i, '/users']
            ];
            var target = '/extensions';
            for (var i = 0; i < map.length; i++) {
                if (map[i][0].test(q)) { target = map[i][1]; break; }
            }
            window.location.href = target + (q ? '?q=' + encodeURIComponent(q) : '');
        });
    }

    // Filter the current page's table as you type in an in-card search box.
    document.querySelectorAll('[data-filter-input]').forEach(function (input) {
        var targetSel = input.getAttribute('data-filter-input');
        input.addEventListener('input', function () {
            var term = input.value.toLowerCase().trim();
            document.querySelectorAll(targetSel + ' tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().indexOf(term) !== -1 ? '' : 'none';
            });
        });
    });

    // ---- Modal open/close --------------------------------------------------
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-modal-open]');
        if (opener) {
            e.preventDefault();
            var m = document.getElementById(opener.getAttribute('data-modal-open'));
            if (m) { m.hidden = false; document.body.style.overflow = 'hidden'; }
            return;
        }

        // Close button or overlay click.
        var closer = e.target.closest('[data-modal-close]');
        if (closer) {
            var overlay = closer.closest('.modal-overlay');
            if (overlay) { overlay.hidden = true; document.body.style.overflow = ''; }
            return;
        }

        if (e.target.classList && e.target.classList.contains('modal-overlay')) {
            e.target.hidden = true;
            document.body.style.overflow = '';
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal-overlay:not([hidden])').forEach(function (m) {
            m.hidden = true;
            document.body.style.overflow = '';
        });
    });

    // ---- Generate a random secret inline -----------------------------------
    document.querySelectorAll('[data-random-target]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var target = document.getElementById(btn.getAttribute('data-random-target'));
            if (!target) return;
            var len = parseInt(btn.getAttribute('data-random-length') || '14', 10);
            var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
            var out = '';
            var buf = new Uint32Array(len);
            (window.crypto || window.msCrypto).getRandomValues(buf);
            for (var i = 0; i < len; i++) { out += alphabet[buf[i] % alphabet.length]; }
            target.value = out;
            target.dispatchEvent(new Event('input'));
        });
    });

    document.querySelectorAll('[data-random-pin]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var target = document.getElementById(btn.getAttribute('data-random-pin'));
            if (!target) return;
            var len = parseInt(btn.getAttribute('data-random-length') || '6', 10);
            var out = '';
            var buf = new Uint32Array(len);
            (window.crypto || window.msCrypto).getRandomValues(buf);
            for (var i = 0; i < len; i++) { out += String(buf[i] % 10); }
            target.value = out;
        });
    });

    // ---- Repeating form rows (IVR keys, ring group members) ----------------
    document.addEventListener('click', function (e) {
        var add = e.target.closest('[data-repeat-add]');
        if (!add) return;
        e.preventDefault();

        var list = document.querySelector(add.getAttribute('data-repeat-add'));
        if (!list) return;

        var template = list.getAttribute('data-repeat-template');
        if (!template) return;

        var count = list.querySelectorAll('.repeat-row').length;
        var html = template.replace(/__INDEX__/g, String(count));

        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var node = wrap.firstElementChild;

        // Provide a remove control for the freshly added row.
        node.querySelectorAll('[data-repeat-remove]').forEach(function (rm) {
            rm.addEventListener('click', function (ev) {
                ev.preventDefault();
                node.remove();
            });
        });

        list.appendChild(node);
        var firstInput = node.querySelector('input, select');
        if (firstInput) firstInput.focus();
    });

    // Wire up remove buttons already present on page load.
    document.querySelectorAll('[data-repeat-remove]').forEach(function (rm) {
        rm.addEventListener('click', function (e) {
            e.preventDefault();
            var row = rm.closest('.repeat-row');
            if (row) row.remove();
        });
    });

    // ---- Destination type -> option list swap --------------------------------
    // When a destination <select> changes type, hide irrelevant option groups.
    document.querySelectorAll('[data-dest-select]').forEach(function (sel) {
        var sync = function () {
            var type = sel.getAttribute('data-dest-type') || '';
            sel.querySelectorAll('optgroup').forEach(function (g) {
                g.hidden = g.getAttribute('data-type') !== type;
            });
        };
        sync();
        // The paired type <select> drives the refresh.
        var typeSel = document.getElementById(sel.getAttribute('data-dest-type-for') || '');
        if (typeSel) {
            typeSel.addEventListener('change', function () {
                sel.setAttribute('data-dest-type', typeSel.value);
                sync();
                // Clear the selection when switching categories.
                sel.selectedIndex = 0;
            });
        }
    });

    // ---- Theme toggle (persisted) -------------------------------------------
    var themeBtn = document.getElementById('themeToggle');
    var saved = null;
    try { saved = localStorage.getItem('panel-theme'); } catch (err) { /* no-op */ }
    if (saved) {
        document.documentElement.setAttribute('data-theme', saved);
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', cur);
            try { localStorage.setItem('panel-theme', cur); } catch (err) { /* no-op */ }
        });
    }

    // ---- Auto-submit selects (filters) ---------------------------------------
    document.querySelectorAll('[data-auto-submit]').forEach(function (el) {
        el.addEventListener('change', function () {
            var form = el.form || el.closest('form');
            if (form) form.submit();
        });
    });

    // ---- Password visibility toggle ------------------------------------------
    document.querySelectorAll('[data-toggle-pw]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var target = document.getElementById(btn.getAttribute('data-toggle-pw'));
            if (!target) return;
            target.type = target.type === 'password' ? 'text' : 'password';
            btn.textContent = target.type === 'password' ? 'Show' : 'Hide';
        });
    });
})();
