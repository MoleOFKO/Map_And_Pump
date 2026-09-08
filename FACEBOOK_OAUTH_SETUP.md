# Facebook OAuth Setup Guide

## 1. Facebook Developer Configuration

### Required Redirect URIs in Facebook Dev Console:
**Settings → Basic → App Domains and Site URLs**

- **Valid OAuth Redirect URIs:**
  ```
  http://localhost:8000/php/Login/social_callback.php?provider=facebook
  https://<your-render-domain>/php/Login/social_callback.php?provider=facebook
  ```

- **App Domains:**
  ```
  localhost:8000
  <your-render-domain>
  ```

### Permissions Required:
1. **email** - Required for user email access
2. **public_profile** - Required for user name and picture

### Test Scope Configuration

**Important:** Facebook requires specific scopes in lowercase with commas:
- App scope: `email,public_profile`
- Token request scope: `email,public_profile`

## 2. Environment Variables

Add these to your deployment platform (Render dashboard):

```yaml
FACEBOOK_CLIENT_ID=<your-app-id>
FACEBOOK_CLIENT_SECRET=<your-app-secret>
APP_BASE_URL=https://<your-render-domain>
```

### Local Development (.env or shell):
```bash
export FACEBOOK_CLIENT_ID=<your-app-id>
export FACEBOOK_CLIENT_SECRET=<your-app-secret>
export APP_BASE_URL=http://localhost:8000
```

## 3. Common Facebook OAuth Errors

| Error | Cause | Fix |
|-------|-------|-----|
| `invalid_client` | Wrong App ID or Secret | Verify credentials in Meta for Developers |
| `redirect_uri_mismatch` | URL doesn't match config | Add exact URI to Valid OAuth Redirect URIs |
| `invalid_request` | Missing or malformed scope | Use lowercase: `email,public_profile` |
| `user_denied` | User cancelled OAuth | Normal - show user permission dialog |
| `access_denied` | App permissions not approved | Request "public_profile" and "email" in app config |

## 4. How Facebook Login Works in Map & Pump

1. User clicks "Login with Facebook"
2. Redirected to: `social_login.php?provider=facebook`
3. App generates state token and stores in session
4. Redirects user to Facebook auth URL
5. User grants permissions
6. Facebook redirects to: `social_callback.php?provider=facebook&code=...&state=...`
7. App exchanges code for access token
8. App fetches user profile (id, name, email, picture)
9. Creates or updates user in database
10. Sets session and redirects to dashboard

## 5. Debug Mode

When Facebook login fails:
1. Login page shows: `Social login failed. Please try again.`
2. Below it shows OAuth debug message with exact error
3. Check PHP error logs: `error_log()` entries under `social_login_failed`

## 6. Test Users

In Meta for Developers:
- **Roles → Test Users** - Create test users to test login without sharing personal data
- Use test user account to verify OAuth flow

## 7. Troubleshooting Checklist

- [ ] Facebook App ID in `FACEBOOK_CLIENT_ID` env var
- [ ] Facebook App Secret in `FACEBOOK_CLIENT_SECRET` env var
- [ ] Redirect URI exactly matches: `/php/Login/social_callback.php?provider=facebook`
- [ ] Permissions "email" and "public_profile" enabled in app config
- [ ] Valid OAuth Redirect URIs include both localhost AND production domain
- [ ] If localhost testing: ensure port 8000 in redirect URI
- [ ] Check PHP error logs for `error_log()` message from `social_callback.php`
- [ ] Verify `allow_url_fopen=On` in PHP ini (required for OAuth)
