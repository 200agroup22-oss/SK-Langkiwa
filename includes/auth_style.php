<?php // Shared look for the public Log in / Register pages. Output inline so the system color theme applies. 
?>
<style>
    body.auth-body {
        font-family: 'Poppins', sans-serif;
        background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 55%, #a5d6a7 100%);
        background-attachment: fixed;
    }

    .auth-card {
        border: 0;
        border-radius: 20px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 20px 50px rgba(27, 94, 32, 0.18), 0 4px 12px rgba(0, 0, 0, 0.06);
    }

    .auth-card .card-header {
        background: linear-gradient(100deg, #45b84d, #aadaad);
        border: 0;
        padding: 26px 20px 22px;
        text-align: center;
        color: #fff;
        font-family: 'Poppins', sans-serif;
        font-size: 18px;
        font-weight: 600;
        line-height: 1.3;
    }

    .auth-card .card-header img {
        display: block;
        margin: 0 auto 10px;
        height: 78px;
        width: 78px;
        object-fit: cover;
        border-radius: 50%;
        border: 4px solid #fff;
        background: #fff;
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
    }

    .auth-card .card-body {
        padding: 28px 32px 22px;
    }

    .auth-card .card-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        font-size: 22px;
        color: #2e7d32;
        margin-bottom: 4px;
    }

    .auth-card .card-body>p {
        color: #6b7a6c;
        font-size: 14px;
        margin-bottom: 20px;
    }

    .auth-card .form-label {
        font-size: 13px;
        font-weight: 600;
        color: #33433a;
        margin-bottom: 5px;
    }

    .auth-card .input-group-text {
        background: #e8f5e9;
        color: #2e7d32;
        border-color: #c8e6c9;
    }

    .auth-card .form-control,
    .auth-card .form-select {
        border-color: #c8e6c9;
        padding: 0.6rem 0.8rem;
        font-size: 14px;
    }

    .auth-card .input-group>:first-child {
        border-top-left-radius: 10px;
        border-bottom-left-radius: 10px;
    }

    .auth-card .input-group>:last-child {
        border-top-right-radius: 10px;
        border-bottom-right-radius: 10px;
    }

    .auth-card .form-control:focus,
    .auth-card .form-select:focus {
        border-color: #45b84d;
        box-shadow: 0 0 0 0.2rem rgba(69, 184, 77, 0.18);
    }

    .auth-card .form-check-input:checked {
        background-color: #45b84d;
        border-color: #45b84d;
    }

    .auth-card .form-text {
        color: #6b7a6c;
    }

    .btn-auth {
        background: linear-gradient(90deg, #45b84d, #409d42);
        color: #fff;
        border: 0;
        border-radius: 10px;
        padding: 0.7rem 1rem;
        font-weight: 600;
        letter-spacing: 0.2px;
        box-shadow: 0 6px 14px rgba(69, 184, 77, 0.3);
        transition: transform 0.15s, box-shadow 0.15s, filter 0.15s;
    }

    .btn-auth:hover,
    .btn-auth:focus {
        color: #fff;
        filter: brightness(0.95);
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(69, 184, 77, 0.38);
    }

    .auth-link {
        color: #409d42;
        font-weight: 600;
        text-decoration: none;
    }

    .auth-link:hover {
        color: #2e7d32;
        text-decoration: underline;
    }

    .auth-card .card-footer {
        background: #f6fbf6;
        border-top: 1px solid #e8f5e9;
        color: #7a8a7b;
        font-size: 12px;
        text-align: center;
        padding: 12px;
    }

    .auth-card .alert {
        border-radius: 10px;
        font-size: 13px;
    }

    /* Edge/IE inject their own built-in "reveal password" eye icon inside password fields —
       hide it so it doesn't double up with this app's own toggle button. */
    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear {
        display: none;
    }

    @media (max-width: 575px) {
        .auth-card .card-body {
            padding: 22px 18px 16px;
        }
    }
</style>