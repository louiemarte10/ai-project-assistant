<?php
/**
 * Template for the GitHub token. Copy to `secret-github.php` (gitignored) and
 * return a GitHub Personal Access Token:
 *   - Fine-grained PAT with **Contents: Read-only** on the relevant repos, OR
 *   - Classic PAT with the **repo** scope (for private repos).
 * Needed for private repos; optional (rate-limited) for public ones.
 */
return 'your-github-pat-here';
?>
