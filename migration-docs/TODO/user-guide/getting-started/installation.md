# Installation

<p class="uk-article-lead">Pagekit's installation process is quick and easy and only takes a few minutes.</p>

<ul class="uk-list">
    <li><a href="#step-1-get-pagekit-and-open-the-installer">Step 1: Get Pagekit and open the Installer</a></li>
    <li><a href="#step-2-language">Step 2: Language</a></li>
    <li><a href="#step-3-database">Step 3: Database</a></li>
    <li><a href="#step-4-site-setup">Step 4: Site setup</a></li>
</ul>

## Step 1: Get Pagekit and open the Installer

**Which method should I choose?**

- **Docker** – Best for local development. Works on Mac, Linux, and Windows (with WSL2). No need to install PHP, MySQL, or Node.js locally.
- **Manual** – Best for production deployment, shared hosting, or Windows without Docker (e.g. XAMPP, Laragon).

**Windows note:** Docker requires WSL2 or Hyper-V. If you prefer a traditional stack, use [Laragon](https://laragon.org/) or XAMPP with PHP 8.2+.

---

You can install Pagekit in two ways: **Docker** (recommended) or **manual setup**.

### Option A: Docker (Recommended)

Docker provides a complete development environment with PHP 8.4, MySQL 8.4, and Node.js pre-configured.

1. **Clone the repository**

   ```bash
   git clone https://github.com/Shadesman5/pagekit.git
   cd pagekit
   ```

2. **Run the setup script** to generate secure passwords and create `docker.env`

   ```bash
   # Windows (PowerShell)
   .\docker-setup.ps1

   # Linux/Mac
   chmod +x docker-setup.sh
   ./docker-setup.sh
   ```

3. **Start the containers**

   ```bash
   # With MySQL (default)
   docker-compose up -d

   # SQLite only (lightweight)
   docker-compose --profile sqlite up -d web node
   ```

4. **Open your browser** and navigate to `http://localhost:8080`. The web installer will appear automatically.

**Note** With Docker, PHP dependencies and frontend assets are built automatically. No manual `composer` or `yarn` steps are required.

### Option B: Manual Setup

1. **Get the source code** – Clone from [GitHub](https://github.com/Shadesman5/pagekit) or download a [release archive](https://github.com/Shadesman5/pagekit/releases). A direct download API at [ttags.de](https://ttags.de) is in development and will offer one-click packages (no composer/yarn build required) – coming soon.

2. **Install PHP dependencies**

   ```bash
   composer install
   ```

3. **Install Node.js dependencies and build frontend assets**

   ```bash
   yarn install
   yarn compile-js --mode=production
   yarn compile-less
   ```

4. **Deploy to your webserver** – Extract or copy the contents to your web root or a subfolder (e.g. `/pagekit`).

5. **Open your browser** and navigate to the URL of your Pagekit installation. You should see the first screen of the web installer.

**Note** Make sure your server meets the [system requirements](requirements.md), including PHP 8.2+ and MySQL 8.0+ (recommended) or SQLite 3.

**Note** If you unzip the package locally before uploading, make sure to include the hidden `.htaccess` file.

---

## Step 2: Language

In the first step of the actual setup process you choose the main language for the site. It will be the default language used on the admin panel and frontend. Both can be changed at any time later.

## Step 3: Database

In this step you enter the details to connect to the database. By default, Pagekit uses SQLite to store your site's data. Here you need to fill in the field _Table Prefix_. The default prefix is `pk_`.

Alternatively, you can choose to use MySQL 8.0+ (recommended). In that case the following details need to be entered.

Field           | Description
--------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------
_Driver_        | Select the database driver: SQLite or MySQL.
_Hostname_      | Enter the host name of your database server. If the webserver and database server are on the same machine, this will be `localhost` or `127.0.0.1`. For Docker, use `mysql` as the hostname.
_User_          | Enter a MySQL username that has access to the database.
_Password_      | Enter the MySQL user's password.
_Database Name_ | Enter the database name.
_Table Prefix_  | You can change the prefix that is used for the database tables. The default prefix is `pk_`.

**Note** Pagekit will try to create the database during installation. You can also do this yourself using a tool like [phpMyAdmin](https://www.phpmyadmin.net/). Feel free to use an existing database. Pagekit prefixes its tables to avoid conflicts.

**Note** When using Docker with MySQL, use hostname `mysql`, database name `pagekit`, and the credentials from your `docker.env` file. phpMyAdmin is available at `http://localhost:8081`.

## Step 4: Site setup

After entering your site's title, you need to create a user account for Pagekit. This user will have admin access and will be able to log in to Pagekit's control panel, once the installation is finished.

Field        | Description
------------ | ------------------------
_Site Title_ | The site title.
_Username_   | Enter the admin username.
_Password_   | Enter the admin password.
_Email_      | Enter the admin email.

**Settings** (gear icon): You can optionally enable _Install demo content_ to populate your site with sample pages, blog posts, and widgets for testing.

Once the installation was successful, you are redirected to the login screen. You can log in to Pagekit's admin panel with the account you've created. If you want to sign in to the admin area in the future, you can always reach the login screen by appending `/admin` to your site's URL.
