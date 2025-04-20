**Secure Unraid WebGUI with acme.sh**

Secure your Unraid WebGUI with a free and trusted SSL certificate using [acme.sh](https://acme.sh) and [ZeroSSL¹](https://zerossl.com).

This plugin automates certificate issuance and renewal using the DNS-01 challenge¹, allowing you to enable HTTPS access to your server without relying on open ports or public web access.

**Features:**

- Use your own domain or subdomain for secure access
- Easily obtain certificates from ZeroSSL¹
- Uses the DNS-01 challenge method (API credentials required)¹
- Keeps your WebGUI secure with scheduled certificate renewals
- Notifications to stay informed about renewals or issues
- Built on the flexible, scriptable acme.sh ACME client
- Free tier ZeroSSL account creation

**Requirements:**

- DNS provider supported by acme.sh (e.g., Cloudflare, Route53, etc.)¹
- An account with ZeroSSL (free tier supported)¹

**Disclaimer:**

- This plugin modifies SSL settings in Unraid, specifically overwriting the self-signed certificate located in `/boot/config/ssl/certs/`.

¹ Pull requests welcome! Contributions to add support for other ACME providers and challenge types are encouraged.
Head over to the [GitHub repository](https://github.com/morgendagen/Unraid-WebGUI-SSL) to contribute.
