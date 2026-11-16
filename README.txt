KRA Nakuru Tax Filing Management System

A PHP and PostgreSQL web system for the Nakuru branch. Citizens register and file tax returns online. Officers and administrators review returns, record payments, issue receipts and view reports.

Requirements
XAMPP (PHP 8.2) installed at C:\xampp
PostgreSQL 18 and pgAdmin 4
VS Code (optional, for editing)

Apache and MySQL in XAMPP are not needed. This project uses PostgreSQL, and PHP's built-in web server runs the site.

First-time setup
Create the database. In pgAdmin, right-click Databases, choose Create > Database, and name it kra_nakuru.
Build the tables. Right-click kra_nakuru, choose Query Tool, paste the full contents of database.sql, and press F5. The script is safe to run more than once.
Set the connection. Open config.php and set the three connection lines:
php
$dsn = "pgsql:host=localhost;port=5432;dbname=kra_nakuru";
$user = "postgres";
$password = "your_postgres_password";
Enable the PostgreSQL driver. In XAMPP, click Config next to Apache, then PHP (php.ini). Make sure these two lines have no ; at the start, then save:
extension=pdo_pgsql
extension=pgsql
Check the driver. Run this in a terminal. You should see pdo_pgsql and pgsql:
powershell
& "C:\xampp\php\php.exe" -m | findstr pgsql
Running the system each time
Make sure the PostgreSQL service (postgresql-x64-18) is running. It normally starts with Windows. You can check it in services.msc.
Open the project in VS Code and open the terminal (Ctrl + `).
The terminal must be in the inner project folder, the one that contains login.php and config.php. If the prompt ends in a single kra_nakuru_tax_system, run:
powershell
cd kra_nakuru_tax_system
Start the server and leave the terminal open:
powershell
& "C:\xampp\php\php.exe" -S localhost:8000
Open http://localhost:8000/login.php.

To start from any folder, point PHP at the project directly:

powershell
& "C:\xampp\php\php.exe" -S localhost:8000 -t "C:\Users\hp\Desktop\kra_nakuru_tax_system\kra_nakuru_tax_system"
Signing in
Who	Username	Password
Administrator	admin	Admin@123 (default; change it with the Change password button on the dashboard)
Citizen	KRA PIN or National ID	the password chosen at registration

The login page has one form and works out whether the person is staff or a citizen. The forms are set up so the browser can offer to save the login after registration or sign-in, and fill it in next time. The saved username is the KRA PIN for citizens. Choose Never on shared computers.

Main features
Citizens: register with a KRA PIN and National ID, file returns, view their own filings and profile, and reset a forgotten password.
Officers and admins: manage taxpayers, review and approve returns, record payments, print receipts, view the audit trail and manage user accounts.
Dashboard: taxpayer and return counts, revenue collected, pending reviews, a monthly filing trend chart and an overdue returns alert.
Payments: record manual payments or request an M-Pesa STK Push. Successful STK callbacks create a payment and printable receipt; pending requests reserve the amount until resolved.
Reports: download returns with paid amounts and balances as a CSV file from the Payments page.
Registration rules
KRA PIN: a letter (A for individuals, P for companies and partnerships), nine digits, and a final Z. Example: A123456789Z.
National ID: 7 or 8 digits.
A KRA PIN or National ID can belong to only one account.
If an officer already created a taxpayer record, the citizen can claim it only if the name and phone number match what is on file.
Forgotten passwords
Citizens: use Forgot password? on the login page. The system checks the KRA PIN, National ID, and the phone number and email on file. If they match, the citizen can set a new password within 10 minutes. No email is sent.
Staff: ask an administrator to reset the password under User Accounts.
Files
File	Purpose
login.php, logout.php	Sign in and out
signup.php	Citizen registration
forgot_password.php, reset_password.php	Citizen password reset
change_password.php	Any signed-in user changes their own password
dashboard.php	Staff dashboard
citizen_dashboard.php, citizen_filing.php, citizen_filings.php, citizen_profile.php	Citizen pages
taxpayers.php, taxpayer_form.php	Manage taxpayers
returns.php, return_form.php	Manage tax returns
payments.php, stk_push.php, mpesa_callback.php, receipt.php	Record payments, initiate STK Push, receive callbacks and print receipts
mpesa.php	Daraja API configuration and request helpers
export_report.php	CSV report download
audit_log.php, users.php	Audit trail and user accounts
config.php	Database connection and shared functions (keep private)
header.php, footer.php, style.css, app.js	Layout and styling
database.sql	Database setup script
Database notes
database.sql is only a stored copy of the setup script. PHP never reads it. Run it in pgAdmin to build or rebuild the database.
When you change the database, for example by adding a column, add the same change to database.sql so the file stays in step with the real database.
After adding the M-Pesa table, run the updated database.sql in pgAdmin on the existing kra_nakuru database before using payments or receipts.
A database trigger keeps citizen accounts in step with taxpayer records. When a taxpayer's name, email or KRA PIN is edited, the linked citizen account is updated automatically. Staff accounts are never changed by it.
Deleting a row from users also deletes the linked taxpayers row and that taxpayer's returns, because of the ON DELETE CASCADE rule. To disable an account, unlink it instead of deleting it.
database.sql ends with a one-time data repair that runs automatically. It unlinks citizen accounts that claimed a taxpayer record under a different name (a bug in an early version of the signup form), and frees the KRA PIN they were using as a username. The schema_fixes table records that it has run, so running the script again will not repeat it. It never deletes rows.
Before using it for real
Change the admin password.
Never share or upload config.php. It holds the database password. If you use Git, add config.php to .gitignore.
Create a dedicated database user with limited rights, instead of the postgres superuser.
Serve the site through Apache or Nginx over HTTPS. PHP's built-in server is for development only.
For STK Push, enable PHP cURL and set MPESA_ENV, MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET, MPESA_SHORTCODE, MPESA_PASSKEY and MPESA_CALLBACK_URL in the PHP server environment. Start with Daraja sandbox credentials. MPESA_CALLBACK_URL must be the public HTTPS address of mpesa_callback.php; localhost alone cannot receive Safaricom callbacks. Never store Daraja secrets in source files or share them in chat.
For a one-terminal Windows test session, set the variables before starting PHP:
powershell
$env:MPESA_ENV = "sandbox"
$env:MPESA_CONSUMER_KEY = "<sandbox-consumer-key>"
$env:MPESA_CONSUMER_SECRET = "<sandbox-consumer-secret>"
$env:MPESA_SHORTCODE = "<sandbox-shortcode>"
$env:MPESA_PASSKEY = "<sandbox-passkey>"
$env:MPESA_CALLBACK_URL = "https://your-public-host/mpesa_callback.php"
& "C:\xampp\php\php.exe" -S localhost:8000
Replace the placeholders with credentials from your Safaricom Daraja sandbox account. Use a public HTTPS tunnel/host for sandbox callback testing; the tunnel must forward requests to this PHP server.
Production payments require an approved Safaricom Daraja account and the shortcode/payment account authorized for the tax collection. Test callback success, cancellation, timeout and duplicate callback cases in sandbox before switching MPESA_ENV to production.
Delete test accounts and sample data.
Delete any info.php file. If the site runs on Apache, block downloads of .sql files.
The login and reset lockouts count attempts per browser session, so a determined attacker can bypass them. A production system should count attempts per account in the database.
The password reset depends on personal details a relative could know. A production system should send a one-time link by email or SMS.
Sample test data

These are fictional. Delete them before real use. The password for all three is Test@1234. To add them in one step, run sample_data.sql in the Query Tool on kra_nakuru after database.sql. It can be run more than once, and it skips anyone already present.

Full name	KRA PIN	National ID	Type
Wanjiru Kamau	A123456789Z	12345678	Individual
Brian Otieno	A234567891Z	23456789	Individual
Mwangi Traders Ltd	P345678912Z	3456789	Company
Troubleshooting
What you see	Likely cause	Fix
"This site can't be reached"	The PHP server is not running	Start it again from the inner project folder
"Not Found" for a page	The file is not in the folder the server runs from	Put it next to login.php
"could not find driver"	pdo_pgsql is not enabled	Edit php.ini and restart the server
"password authentication failed"	The password in config.php is wrong	Set the correct PostgreSQL password
"could not connect to server"	PostgreSQL is not running	Start postgresql-x64-18 in services.msc
relation "..." does not exist	database.sql was not run on kra_nakuru	Run it in the Query Tool on kra_nakuru
pgAdmin keeps asking for the password	Its saved password is out of date	Retype it under Properties > Connection