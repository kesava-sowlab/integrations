# WordPress Circle–Teachable Integration Plugin

This plugin integrates your WordPress site with **Circle** and **Teachable**. It enables webhook handling for new Teachable enrollments and allows you to manage Circle communities and space groups from your WordPress admin dashboard.

---

## 🚀 Installation

You can install the plugin in one of two ways:

### 🔧 Method 1: Manual Installation
1. Copy the `Integrations.php` file.
2. Paste it into your local WordPress plugin directory:
    xampp > htdocs > your-wordpress-site > wp-content > plugins
3. Rename the file or wrap it in a folder if needed.
4. Go to your WordPress Admin Dashboard → **Plugins** → Activate the plugin.

### 📦 Method 2: Zip Upload
1. Create a `.zip` file containing `Integrations.php`.
2. In your WordPress Admin:
- Go to **Plugins** → **Add New** → **Upload Plugin**.
- Click **Choose File**, select your ZIP file, and upload it.
3. Activate the plugin after upload.

---

## ⚙️ Setting Up the Plugin

1. After activation, go to **Integrations → Settings**.
2. Choose a **Delete Interval** for any scheduled cleanup (optional).

---

## ✅ Enabling Teachable Webhook

1. Log in to your **Teachable** admin panel.
2. Go to:  
**School → Settings → Webhooks → Add Webhook**
3. For **Webhook URL**, enter:
    https://your-site.com/wp-json/teachable/v1/enrollment
- Replace `your-site.com` with your actual public WordPress domain.
- The URL **must be publicly accessible**.
4. Set **Webhook Event** to:  
`Custom` → Tick **New Enrollment**  
5. Click **Save**.

---

## 🔑 Getting and Saving API Keys

### 1. Circle API Keys
- Log into **Circle**, then:
- Go to your **Community → Developers → Tokens**
- Create **two tokens**:
 - `v1` token
 - `v2` token
- Copy both tokens.
- Go to your WordPress Admin:
- **Integrations → Settings**
- Paste the tokens in their respective fields.
- Click **Save Tokens**.

### 2. Teachable API Key
- In Teachable:
- Go to **School → Settings → API Keys**
- Create an API key and give it a name.
- Copy the key.
- In WordPress:
- Go to **Integrations → Settings**
- Paste the key into **Teachable API Token**
- Save your settings.

---

## 🧭 Final Configuration

1. After saving the tokens:
- **Select your Circle Community** from the dropdown.
- Click **Save Community**.
2. Then:
- **Select a Space Group** under the same settings page.
- Click **Save Space Group**.

You're done! The plugin is now ready to use with Circle and Teachable.


