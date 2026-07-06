Work context
Saurabh Bhoma is a software engineer and code reviewer at Singularity Credit (also referred to as Singularity Creditworld), working on a fintech platform called Velocity — a Laravel/PHP-based system spanning three microservices: velocity-los-api (Loan Origination System), velocity-lms-api (Loan Management System), and velocity-crm-api. Saurabh functions as a tech lead or senior reviewer responsible for approving cross-repo feature changes and managing project tickets via GitLab and OpenProject.

Top of mind
He is also actively using Claude Desktop with MCP integrations — including OpenProject (openproject-fastmcp) and GitLab — to manage tickets and conduct code reviews, and recently worked on adding a Microsoft Teams MCP server to his Claude Desktop configuration.

OpenProject workflow rules:

Saurabh's user ID: 50.
Velocity project ID: 19.
Verified status IDs: New=1, In Progress=7, Developed=8, In Dev Testing=15, In QA Testing=9, Test Failed=11, Code Review=17, Ready for Deployment=10, Prod Deployed=16, Closed=12, On Hold=13, Rejected=14.
Standard closure path from New: New → In Progress(7) → Developed(8) → In Dev Testing(15) → In QA Testing(9) → Ready for Deployment(10) → Prod Deployed(16) → Closed(12). Direct status jumps are not permitted; each intermediate step must be called sequentially via update_work_package.
From "Specified" status: Specified → In Progress(7) → then standard path to Closed(12).
From "Test Failed" to "In QA Testing": Test Failed → In Progress(7) → Developed(8) → In Dev Testing(15) → In QA Testing(9).

GitLab project paths and local setup:
velocity-lms-api: local path C:\laragon\www\vLms, remote https://kuber.sardardi.in/velocity/velocity-lms-api/
velocity-los-api: local path C:\laragon\www\vLos, remote https://kuber.sardardi.in/velocity/velocity-los-api/
velocity-crm-api: local path C:\laragon\www\vCrm, remote https://kuber.sardardi.in/velocity/velocity-crm-api/
velocity-auth-api: local path C:\laragon\www\vAuth, remote https://kuber.sardardi.in/velocity/velocity-auth-api/
Code review steps: get branch name → checkout locally → git pull origin {branch} → git pull origin master → then begin review.

MR Review Protocol: When any MR is shared for review — (1) review seriously and thoroughly, (2) check security gates and code quality, (3) check for syntax issues, (4) check for bugs and logic errors, (5) post inline comments on specific lines using gitlab:create_merge_request_thread, (6) post a full summary comment on the MR using gitlab:create_merge_request_note, and (7) after posting the GitLab review, send the review summary (key findings + MR link) to the MR author as a 1:1 DM via the Teams MCP (teams-mcp) — match the author to the correct Teams user before sending. Always deliver feedback directly on the MR via GitLab MCP tools and notify the author via Teams DM.