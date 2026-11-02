<?php
// Dedicated platform metadata portal. No tenant financial impersonation is implemented.
declare(strict_types=1);
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Finance 360 Platform Administration</title><link rel="stylesheet" href="assets/f28/platform.css"></head>
<body><main><header><span>FINANCE 360</span><h1>Platform administration</h1><p>Restaurant onboarding and trial administration · staging foundation</p></header>
<section id="login"><h2>Administrator sign-in</h2><form id="login-form"><label>Email<input id="email" type="email" autocomplete="username" required maxlength="190"></label><label>Password<input id="password" type="password" autocomplete="current-password" required></label><button type="submit">Sign in</button></form></section>
<section id="mfa" hidden><h2>Authenticator verification</h2><form id="mfa-form"><label>Six-digit code<input id="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label><button type="submit">Verify</button></form></section>
<section id="workspace" hidden><div class="toolbar"><h2>Restaurants</h2><button id="refresh" type="button">Refresh</button><button id="logout" type="button">Sign out</button></div><p>This portal shows account metadata. It does not provide access to restaurant financial records or payment collection. Trial extensions and suspension are audited.</p><div id="tenants"></div></section>
<p id="status" role="status" aria-live="polite"></p><footer>Access requires explicitly provisioned platform membership and MFA. Restaurant owner access does not grant platform administration.</footer></main><script src="assets/f28/platform.js" defer></script></body></html>
