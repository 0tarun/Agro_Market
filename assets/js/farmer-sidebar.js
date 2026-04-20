/**
 * farmer-sidebar.js
 * Fetches the logged-in farmer's profile data and updates the sidebar
 * profile image, name, and location on every farmer dashboard page.
 * Include this script at the bottom of every farmer-*.html page.
 */
(function loadSidebarProfile() {
    var profileCacheKey = 'farmerProfileCache';
    var candidates = ['../../api/farmer/farmer-account-data.php', '../../farmer-account-data.php', 'farmer-account-data.php', '/farmer-account-data.php'];
    var pathSegments = window.location.pathname.split('/').filter(Boolean);
    if (pathSegments.length > 0 && pathSegments[0].indexOf('.') === -1) {
        candidates.push('/' + pathSegments[0] + '/api/farmer/farmer-account-data.php');
    }

    function fetchProfile(index) {
        if (index >= candidates.length) {
            return Promise.resolve(null);
        }

        return fetch(candidates[index], {
            method: 'GET',
            credentials: 'same-origin'
        })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Request failed');
                }

                return res.json();
            })
            .catch(function () {
                return fetchProfile(index + 1);
            });
    }

    function resolveBasePath() {
        var parts = window.location.pathname.split('/').filter(Boolean);
        if (parts.length > 0 && parts[0].indexOf('.') === -1) {
            return '/' + parts[0];
        }

        return '';
    }

    function normalizeProfileImage(url) {
        var raw = (url || '').toString().trim();
        var basePath = resolveBasePath();

        if (!raw) {
            return (basePath || '') + '/figma/images (5).jpg';
        }

        if (/^(https?:)?\/\//i.test(raw) || raw.indexOf('data:') === 0) {
            return raw;
        }

        raw = raw.replace(/\\/g, '/');
        if (raw.charAt(0) !== '/') {
            raw = '/' + raw.replace(/^\/+/, '');
        }

        if (basePath && raw.indexOf(basePath + '/') !== 0) {
            raw = basePath + raw;
        }

        return raw;
    }

    function getCachedProfile() {
        try {
            var raw = sessionStorage.getItem(profileCacheKey);
            if (!raw) {
                return null;
            }

            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function setCachedProfile(profile) {
        try {
            sessionStorage.setItem(profileCacheKey, JSON.stringify(profile));
        } catch (e) {
            // Ignore storage write errors.
        }
    }

    function applyImageWithFallback(imgEl, desiredUrl) {
        if (!imgEl) {
            return;
        }

        var fallback = normalizeProfileImage('figma/images (5).jpg');
        var target = normalizeProfileImage(desiredUrl);

        if (!target) {
            imgEl.src = fallback;
            return;
        }

        imgEl.onerror = function () {
            imgEl.onerror = null;
            imgEl.src = fallback;
        };

        if (imgEl.src !== target) {
            imgEl.src = target;
        }
    }

    function applyProfileToSidebar(profile) {
        if (!profile) {
            return;
        }

        var sidebarImg = document.getElementById('sidebarProfileImage');
        var sidebarName = document.getElementById('sidebarProfileName');
        var sidebarLocation = document.getElementById('sidebarProfileLocation');

        applyImageWithFallback(sidebarImg, profile.profile_image);
        if (sidebarName && profile.full_name) sidebarName.textContent = profile.full_name;
        if (sidebarLocation && profile.location_text) sidebarLocation.textContent = profile.location_text;
    }

    applyProfileToSidebar(getCachedProfile());

    fetchProfile(0)
        .then(function (data) {
            if (!data || data.ok !== true) {
                // Not logged in or not a farmer — just keep sidebar defaults
                return;
            }

            // Build location string from division/district
            var locationParts = [];
            if (data.district) locationParts.push(data.district);
            if (data.division) locationParts.push(data.division);

            var profile = {
                profile_image: data.profile_image || '',
                full_name: data.full_name || '',
                location_text: (locationParts.length > 0 ? locationParts.join(', ') : '')
            };

            applyProfileToSidebar(profile);
            setCachedProfile(profile);
        })
        .catch(function () {
            // Sidebar keeps default values if request fails
        });
})();
