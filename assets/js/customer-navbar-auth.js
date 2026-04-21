(function setupCustomerNavbarAuth() {
    var navAuth = document.querySelector('.nav-auth');
    if (!navAuth) {
        return;
    }

    var loginBtn = navAuth.querySelector('.btn-login');
    var registerBtn = navAuth.querySelector('.btn-register');
    var cartLink = navAuth.querySelector('.nav-cart');

    if (!loginBtn || !registerBtn) {
        return;
    }

    function resolveBasePath() {
        var parts = window.location.pathname.split('/').filter(Boolean);
        if (parts.length > 0 && parts[0].indexOf('.') === -1) {
            return '/' + parts[0];
        }

        return '';
    }

    function appPath(path) {
        var basePath = resolveBasePath();
        return (basePath || '') + path;
    }

    function applyGuestNav() {
        loginBtn.textContent = 'Login';
        loginBtn.href = appPath('/pages/auth/Login.html');

        registerBtn.textContent = 'Register';
        registerBtn.href = appPath('/pages/auth/Registration.html');

        if (cartLink) {
            cartLink.style.display = 'none';
        }
    }

    function applyCustomerNav() {
        loginBtn.textContent = 'My Account';
        loginBtn.href = appPath('/pages/customer/customer-account.html');

        registerBtn.textContent = 'Logout';
        registerBtn.href = appPath('/api/auth/logout.php');

        if (cartLink) {
            cartLink.style.display = 'inline-flex';
            cartLink.href = appPath('/pages/customer/view-cart.html');
        }
    }

    function applyFarmerNav() {
        loginBtn.textContent = 'My Account';
        loginBtn.href = appPath('/pages/farmer/farmer-dashboard.html');

        registerBtn.textContent = 'Logout';
        registerBtn.href = appPath('/api/auth/logout.php');

        if (cartLink) {
            cartLink.style.display = 'none';
        }
    }

    applyGuestNav();

    fetch(appPath('/api/auth/session-status.php'), {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Session request failed');
            }

            return response.json();
        })
        .then(function (data) {
            if (data && data.ok && data.is_authenticated === true && data.is_customer === true) {
                applyCustomerNav();
                return;
            }

            if (data && data.ok && data.is_authenticated === true && data.is_farmer === true) {
                applyFarmerNav();
                return;
            }

            applyGuestNav();
        })
        .catch(function () {
            applyGuestNav();
        });
})();
