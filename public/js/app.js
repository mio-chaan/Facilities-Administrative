/**
 * app.js
 * General site behavior: mobile sidebar toggle, auto-dismiss flash
 * messages. Module-specific JS lives in the module's own file,
 * loaded conditionally from templates/footer.php - keep this file
 * generic, not module-aware.
 *
 * REDESIGN ADDITION: a second, independent block below adds the
 * optional desktop sidebar collapse toggle (#t8SidebarCollapseToggle,
 * templates/sidebar.php). It only touches a new class
 * (.t8-sidebar-collapsed on .t8-shell) and does not modify or
 * interfere with the mobile toggle logic above it.
 *
 * DASHBOARD UPDATE: a third block adds the notification bell popover
 * (templates/navbar.php, #t8NotifBell / #t8NotifPopover). It's here
 * (not dashboard.js) because the navbar/bell render on every page,
 * not just the dashboard.
 */

document.addEventListener("DOMContentLoaded", function () {
    var toggleBtn = document.getElementById("t8SidebarToggle");
    var sidebar = document.getElementById("t8Sidebar");

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener("click", function () {
            sidebar.classList.toggle("t8-sidebar-open");
        });
    }

    var alerts = document.querySelectorAll(".t8-flash-stack .t8-alert");
    alerts.forEach(function (alertEl) {
        setTimeout(function () {
            alertEl.style.transition = "opacity 0.3s ease";
            alertEl.style.opacity = "0";
            setTimeout(function () {
                alertEl.remove();
            }, 300);
        }, 5000);
    });
});

document.addEventListener("DOMContentLoaded", function () {
    var collapseBtn = document.getElementById("t8SidebarCollapseToggle");
    var shell = document.querySelector(".t8-shell");
    var storageKey = "t8SidebarCollapsed";

    function setCollapsedState(collapsed) {
        if (!shell) {
            return;
        }

        if (collapsed) {
            shell.classList.add("t8-sidebar-collapsed");
            if (collapseBtn) {
                collapseBtn.setAttribute("aria-label", "Expand sidebar");
                collapseBtn.querySelector("i")?.classList.replace("fa-angles-left", "fa-angles-right");
                collapseBtn.querySelector("span").textContent = "Expand";
            }
        } else {
            shell.classList.remove("t8-sidebar-collapsed");
            if (collapseBtn) {
                collapseBtn.setAttribute("aria-label", "Collapse sidebar");
                collapseBtn.querySelector("i")?.classList.replace("fa-angles-right", "fa-angles-left");
                collapseBtn.querySelector("span").textContent = "Collapse";
            }
        }

        try {
            localStorage.setItem(storageKey, collapsed ? "1" : "0");
        } catch (error) {
            // ignore storage errors in private mode or restricted browsers
        }
    }

    if (shell) {
        var savedState = null;
        try {
            savedState = localStorage.getItem(storageKey);
        } catch (error) {
            savedState = null;
        }

        if (savedState === "0") {
            setCollapsedState(false);
        } else if (savedState === "1") {
            setCollapsedState(true);
        }
    }

    if (collapseBtn && shell) {
        collapseBtn.addEventListener("click", function () {
            var isCollapsed = shell.classList.contains("t8-sidebar-collapsed");
            setCollapsedState(!isCollapsed);
        });
    }
});

document.addEventListener("DOMContentLoaded", function () {
    var bell = document.getElementById("t8NotifBell");
    var popover = document.getElementById("t8NotifPopover");
    if (!bell || !popover) {
        return; // navbar not present on this render (e.g. login page)
    }

    var wrap = bell.closest(".t8-notif-wrap");
    var markAllBtn = document.getElementById("t8NotifMarkAll");
    var bellDot = document.getElementById("t8NotifBellDot");
    var csrfInput = popover.querySelector('input[name="csrf_token"]');
    var actionUrl = popover.getAttribute("data-action-url") || "notifications_action.php";

    function csrfToken() {
        return csrfInput ? csrfInput.value : "";
    }

    function updateBellDot(unreadCount) {
        if (!bellDot) {
            if (unreadCount > 0) {
                bellDot = document.createElement("span");
                bellDot.className = "t8-navbar-bell-dot";
                bellDot.id = "t8NotifBellDot";
                bell.appendChild(bellDot);
            } else {
                return;
            }
        }
        if (unreadCount > 0) {
            bellDot.textContent = String(Math.min(unreadCount, 99));
            bellDot.style.display = "";
        } else {
            bellDot.remove();
            bellDot = null;
        }
    }

    function closePopover() {
        popover.classList.remove("t8-open");
        bell.setAttribute("aria-expanded", "false");
    }

    function openPopover() {
        popover.classList.add("t8-open");
        bell.setAttribute("aria-expanded", "true");
    }

    bell.addEventListener("click", function (e) {
        e.stopPropagation();
        if (popover.classList.contains("t8-open")) {
            closePopover();
        } else {
            openPopover();
        }
    });

    document.addEventListener("click", function (e) {
        if (wrap && !wrap.contains(e.target)) {
            closePopover();
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") {
            closePopover();
        }
    });

    function markRead(id, itemEl) {
        var body = new URLSearchParams();
        body.append("action", "mark_read");
        body.append("id", id);
        body.append("csrf_token", csrfToken());

        fetch(actionUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest"
            },
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    itemEl.classList.remove("t8-notif-item-unread");
                    updateBellDot(data.unread || 0);
                }
            })
            .catch(function () { /* fail quietly — non-critical UI action */ });
    }

    popover.querySelectorAll(".t8-notif-item").forEach(function (itemEl) {
        itemEl.addEventListener("click", function () {
            if (itemEl.classList.contains("t8-notif-item-unread")) {
                markRead(itemEl.getAttribute("data-notif-id"), itemEl);
            }
        });
    });

    if (markAllBtn) {
        markAllBtn.addEventListener("click", function () {
            var body = new URLSearchParams();
            body.append("action", "mark_all");
            body.append("csrf_token", csrfToken());

            fetch(actionUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                    "Accept": "application/json",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: body.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.ok) {
                        popover.querySelectorAll(".t8-notif-item-unread").forEach(function (el) {
                            el.classList.remove("t8-notif-item-unread");
                        });
                        updateBellDot(0);
                    }
                })
                .catch(function () { /* fail quietly */ });
        });
    }
});
