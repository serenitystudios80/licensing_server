# 🔐 License Validation Server

A complete, production-ready license validation server for Serenity Booking WordPress plugin.

## 🎯 What This Is

A standalone license server that:
- ✅ Validates WordPress plugin licenses
- ✅ Handles subscription payments via Razorpay
- ✅ Manages multi-site activations
- ✅ Auto-expires annual licenses with grace periods
- ✅ Provides admin dashboard for management
- ✅ Protects APIs with HMAC authentication & rate limiting

## ✨ Features

### Core API Endpoints
- **POST /create-license** - Create licenses after payment (serenitystudios.in integration)
- **POST /activate** - Activate license on WordPress site
- **POST /validate** - Check license status (with lazy expiration check)
- **POST /deactivate** - Remove license from a site

### Security
- **HMAC-SHA256** authentication (±300s time window)
- **Rate limiting** (per-IP and per-license-key)
- **Trusted proxy** support for X-Forwarded-For
- **Fail-open** design (rate limiting doesn't block on errors)

### Admin Dashboard
- **Dashboard** - Revenue metrics, license health
- **License Management** - Search, filter, pagination
- **Session Auth** - Secure admin login with lockout protection

### License Lifecycle
- **Tiers**: Annual (1 year) | Lifetime (never expires)
- **Statuses**: Active → Grace (72h) → Expired | Revoked
- **Auto-expiration**: Lazy Check on /validate + Sweep Job (cron)
- **Audit log**: Immutable event tracking

## 🚀 Quick Start

### 1. Install Dependencies

```bash
composer install
```

### 2. Configure Environment

Copy `.env.example` to `.env` and configure:

```env
DB_HOST=localhost
DB_NAME=license_server
DB_USER=your_user
DB_PASS=your_password

RAZORPAY_KEY_ID=rzp_test_...
RAZORPAY_WEBHOOK_SECRET=whsec_...
HMAC_SHARED_SECRET=your-super-secret-key

TRUSTED_PROXY_RANGES=
RATE_LIMIT_IP_MAX=100
RATE_LIMIT_IP_WINDOW_SECONDS=60
RATE_LIMIT_KEY_MAX=50
RATE_LIMIT_KEY_WINDOW_SECONDS=60

SESSION_SECRET=another-secret-key
```

### 3. Run Migrations

```bash
php migrations/run.php
```

### 4. Create Admin User

```sql
INSERT INTO admin_users (username, password_hash, created_at)
VALUES ('admin', '$2y$10$...', NOW());
-- Use PHP to generate: password_hash('your-password', PASSWORD_DEFAULT)
```

### 5. Access Admin Panel

```
https://your-domain.com/admin/login.php
```

## 📚 Documentation

- **[IMPLEMENTATION_COMPLETE.md](IMPLEMENTATION_COMPLETE.md)** - Complete implementation guide
- **[PAYMENT_INTEGRATION.md](PAYMENT_INTEGRATION.md)** - Payment integration setup
- **[INTEGRATION_SUMMARY.md](INTEGRATION_SUMMARY.md)** - Quick integration reference
- **[CURRENT_STATUS.md](CURRENT_STATUS.md)** - Implementation status

## 🔧 API Usage

### Authentication

All API endpoints require HMAC authentication:

```php
$timestamp = time();
$body = json_encode($payload);
$signature = hash_hmac('sha256', "{$timestamp}.{$body}", $hmacSecret);

$headers = [
    'Content-Type: application/json',
    "X-Timestamp: {$timestamp}",
    "X-Signature: {$signature}",
];
```

### Example: Activate License

```php
$payload = [
    'license_key' => 'SERB-XXXXX-XXXXX-XXXXX-XXXXX',
    'site_url' => 'https://example.com',
];

$ch = curl_init('https://your-domain.com/activate');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => $headers,
]);

$response = curl_exec($ch);
```

## 🗄️ Database Structure

### licenses
Main license records with status, tier, expiration

### license_activations
Track which WordPress sites are using each license

### license_events
Append-only audit log of all license changes

### admin_users
Admin panel authentication

### admin_login_attempts
Brute-force protection tracking

### rate_limit_store
Rate limiting request tracking

## 📁 Project Structure

```
/src
  /Admin          - Dashboard, session auth
  /Api            - API endpoint handlers
  /Config         - Configuration management
  /Domain         - Business logic (License, StatusCalculator)
  /Http           - Request/response, routing
  /Repository     - Database access
  /Security       - HMAC, IP resolution
  /RateLimit      - Rate limiting
  /Support        - Utilities (Clock, Logger)

/public
  /admin          - Admin UI (dashboard, licenses list)
  index.php       - API entry point

/migrations       - Database schema
```

## 🎭 Integration Points

### 1. Payment Site (serenitystudios.in)
After successful Razorpay payment:
- Call `POST /create-license`
- Get license key
- Email to customer

### 2. WordPress Plugin
Customer enters license key:
- Call `POST /activate` with license_key + site_url
- Periodically call `POST /validate` to check status
- Handle grace period (show warning)
- Disable features on expiration

### 3. Razorpay Webhooks (Optional)
For automatic renewal handling:
- Implement `POST /webhook/razorpay`
- Handle subscription.charged success/failure
- Auto-extend annual licenses

## ⚙️ Configuration

### Rate Limiting
- `RATE_LIMIT_IP_MAX` - Max requests per IP in window
- `RATE_LIMIT_IP_WINDOW_SECONDS` - IP rate limit window
- `RATE_LIMIT_KEY_MAX` - Max requests per license key in window
- `RATE_LIMIT_KEY_WINDOW_SECONDS` - License key rate limit window

### HMAC
- `HMAC_SHARED_SECRET` - Shared secret for API authentication
- Time window: ±300 seconds (hardcoded for security)

### Trusted Proxies
- `TRUSTED_PROXY_RANGES` - Comma-separated IPs/CIDR ranges
- Example: `10.0.0.1,192.168.1.0/24`

## 🧪 Testing

### Test License Creation

```bash
curl -X POST https://your-domain.com/create-license \
  -H "Content-Type: application/json" \
  -H "X-Timestamp: $(date +%s)" \
  -H "X-Signature: <signature>" \
  -d '{
    "email": "test@example.com",
    "customer_name": "Test User",
    "product": "serenity-booking",
    "tier": "lifetime",
    "activation_limit": 1
  }'
```

### Test Activation

```bash
curl -X POST https://your-domain.com/activate \
  -H "Content-Type: application/json" \
  -H "X-Timestamp: $(date +%s)" \
  -H "X-Signature: <signature>" \
  -d '{
    "license_key": "SERB-XXXXX-XXXXX-XXXXX-XXXXX",
    "site_url": "https://example.com"
  }'
```

## 📊 Admin Dashboard Features

### Dashboard Metrics
- Active licenses count
- Monthly Recurring Revenue (MRR)
- Lifetime licenses count
- Licenses expiring soon (7 days)
- Licenses in grace period

### License Management
- Search by email or license key
- Filter by status (active/grace/expired/revoked)
- Filter by tier (annual/lifetime)
- Paginated view (25 per page)

## 🔒 Security Features

- ✅ HMAC-SHA256 request signing
- ✅ Timestamp validation (±5 minutes)
- ✅ Rate limiting (per-IP + per-license-key)
- ✅ SQL injection prevention (prepared statements)
- ✅ Password hashing (bcrypt)
- ✅ Session management with inactivity timeout
- ✅ Brute-force protection (5 failures / 15 min lockout)
- ✅ Secret redaction in logs

## 📝 License Key Format

```
SERB-XXXXX-XXXXX-XXXXX-XXXXX
```

- Prefix: `SERB` (Serenity Booking)
- 4 blocks of 5 characters each
- Characters: A-Z, 2-9 (excludes confusing chars like I, O, 0, 1)
- Total length: 24 characters (including hyphens)

## 🎯 What's NOT Implemented Yet

- ⏳ Webhook endpoint (`/webhook/razorpay`)
- ⏳ Sweep Job cron script
- ⏳ License detail view in admin
- ⏳ Manual admin actions (revoke, extend, regenerate)
- ⏳ CSV export
- ⏳ Manual license issuance UI

These are optional enhancements. The core system is **fully functional** for:
- Creating licenses after payment
- Activating licenses on WordPress sites
- Validating license status
- Managing licenses via admin panel

## 🚀 Deployment

### Requirements
- PHP 8.2+ with PDO, JSON, OpenSSL extensions
- MariaDB 10.5+ (or MySQL 8.0+)
- Web server (Apache/Nginx) with HTTPS
- Composer for dependencies

### Production Checklist
- [ ] Set strong `HMAC_SHARED_SECRET`
- [ ] Set strong `SESSION_SECRET`
- [ ] Configure rate limits appropriately
- [ ] Set up HTTPS (required for security)
- [ ] Configure trusted proxy ranges if behind CDN/proxy
- [ ] Run database migrations
- [ ] Create admin user
- [ ] Test API endpoints
- [ ] Set up error logging
- [ ] Configure backups

## 🆘 Support

For issues or questions:
1. Check IMPLEMENTATION_COMPLETE.md for API docs
2. Check PAYMENT_INTEGRATION.md for integration setup
3. Review error logs at configured log path
4. Test with curl before debugging WordPress integration

## 📄 License

Proprietary - Serenity Studios

---

**Built with** ❤️ **for Serenity Studios**
