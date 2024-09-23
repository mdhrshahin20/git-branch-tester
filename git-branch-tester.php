<?php
/*
Plugin Name: GitHub Branch Tester
Description: A WordPress plugin to test branches of another GitHub plugin, including support for private repositories.
Version: 1.5
Author: Your Name
*/

// Security check
if (!defined('ABSPATH')) {
    exit;
}

// Default GitHub repository
define('DEFAULT_GITHUB_REPO', 'mdhrshahin20/rex-toaster');

// Add settings page for branch selection and GitHub token
function github_branch_tester_menu() {
    add_options_page('GitHub Branch Tester', 'GitHub Branch Tester', 'manage_options', 'github-branch-tester', 'github_branch_tester_settings_page');
}
add_action('admin_menu', 'github_branch_tester_menu');

// Settings page HTML
function github_branch_tester_settings_page() {
    ?>
    <div class="wrap">
        <h1>GitHub Branch Tester</h1>
        <form method="post" action="">
            <p>
                <strong>GitHub Access Token:</strong>
                <input type="text" name="github_branch_tester_access_token" value="<?php echo esc_attr(get_option('github_branch_tester_access_token', '')); ?>" size="50" />
                <span class="description">Enter your GitHub Personal Access Token.</span>
            </p>
            <p>
                <strong>GitHub Repository:</strong>
                <input type="text" name="github_branch_tester_repo" value="<?php echo esc_attr(get_option('github_branch_tester_repo', DEFAULT_GITHUB_REPO)); ?>" size="50" />
                <span class="description">Enter the GitHub repository in the format <code>username/repo</code>.</span>
            </p>
            <p>
                <button type="submit" name="action" value="authenticate">Authenticate</button>
            </p>
        </form>
        <form method="post" action="">
            <p>
                <strong>Select GitHub Branch:</strong>
                <?php github_branch_tester_branch_dropdown(); ?>
            </p>
            <p>
                <button type="submit" name="action" value="apply_branch">Apply Branch</button>
            </p>
        </form>
    </div>
    <?php
}

// Fetch branches from GitHub API
function github_branch_tester_fetch_branches() {
    $repo = get_option('github_branch_tester_repo', DEFAULT_GITHUB_REPO);
    $api_url = 'https://api.github.com/repos/' . $repo . '/branches';
    $access_token = get_option('github_branch_tester_access_token', '');

    // Set up headers for authentication
    $args = array(
        'headers' => array(
            'Authorization' => 'token ' . $access_token,
            'User-Agent'    => 'WordPress/GitHubBranchTester',
            'Accept'        => 'application/vnd.github.v3+json'
        )
    );

    // Call GitHub API
    $response = wp_remote_get($api_url, $args);

    // Check for errors
    if (is_wp_error($response)) {
        error_log('Error fetching branches: ' . $response->get_error_message());
        return [];
    }

    $body = wp_remote_retrieve_body($response);

    // Validate JSON response
    $branches = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON error: ' . json_last_error_msg());
        return [];
    }

    return is_array($branches) ? $branches : [];
}

// Create dropdown for selecting a branch
function github_branch_tester_branch_dropdown() {
    $branches = github_branch_tester_fetch_branches();

    if (empty($branches)) {
        echo '<p>Error fetching branches. Please authenticate first.</p>';
        return;
    }

    ?>
    <select name="github_branch_tester_selected_branch">
        <option value="">Select a Branch</option>
        <?php foreach ($branches as $branch): ?>
            <option value="<?php echo esc_attr($branch['name']); ?>">
                <?php echo esc_html($branch['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

// Handle authentication and saving the access token and repo
function github_branch_tester_authenticate() {
    if (isset($_POST['action']) && $_POST['action'] === 'authenticate') {
        $access_token = sanitize_text_field($_POST['github_branch_tester_access_token']);
        $repo = sanitize_text_field($_POST['github_branch_tester_repo']);

        if (!empty($access_token)) {
            update_option('github_branch_tester_access_token', $access_token);
            update_option('github_branch_tester_repo', $repo);
            add_settings_error('github_branch_tester_messages', 'auth_success', 'Authenticated successfully!', 'updated');
        } else {
            add_settings_error('github_branch_tester_messages', 'auth_error', 'Access token cannot be empty.', 'error');
        }
    }
}
add_action('admin_init', 'github_branch_tester_authenticate');

// Handle applying the selected branch
function github_branch_tester_apply_branch() {
    if (isset($_POST['action']) && $_POST['action'] === 'apply_branch') {
        $branch = isset($_POST['github_branch_tester_selected_branch']) ? sanitize_text_field($_POST['github_branch_tester_selected_branch']) : '';

        if (!empty($branch)) {
            $result = github_branch_tester_download_plugin($branch);

            if (is_wp_error($result)) {
                add_settings_error('github_branch_tester_errors', 'branch_error', 'Error: ' . $result->get_error_message(), 'error');
            } else {
                add_settings_error('github_branch_tester_errors', 'branch_success', 'Branch ' . esc_html($branch) . ' downloaded successfully!', 'updated');
            }
        } else {
            add_settings_error('github_branch_tester_errors', 'branch_error', 'Please select a branch first.', 'error');
        }
    }
}
add_action('admin_init', 'github_branch_tester_apply_branch');


function github_branch_tester_download_plugin($branch) {
    // Initialize WordPress filesystem API
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    global $wp_filesystem;
    WP_Filesystem(); // Initialize the filesystem API

    $access_token = get_option('github_branch_tester_access_token', '');
    $repo = get_option('github_branch_tester_repo', DEFAULT_GITHUB_REPO);
    $repo_url = 'https://github.com/' . $repo . '/archive/refs/heads/' . $branch . '.zip';

    // Set headers for authenticated GitHub API request
    $args = array(
        'headers' => array(
            'Authorization' => 'token ' . $access_token,
            'User-Agent'    => 'WordPress/GitHubBranchTester'
        )
    );

    // Fetch the ZIP file using wp_remote_get
    $response = wp_remote_get($repo_url, $args);

    // Check for errors during the request
    if (is_wp_error($response)) {
        error_log('Download error: ' . $response->get_error_message());
        return $response; // Return error if download fails
    }

    // Get the body of the response (ZIP file content)
    $zip_file_content = wp_remote_retrieve_body($response);

    // Create a temporary file to save the ZIP content
    $zip_file_path = tempnam(sys_get_temp_dir(), 'plugin_zip');
    file_put_contents($zip_file_path, $zip_file_content);

    // Use download_url to handle the download and unzip
    $result = unzip_file($zip_file_path, WP_PLUGIN_DIR);
    // Cleanup temporary ZIP file
    if (file_exists($zip_file_path)) {
        unlink($zip_file_path);
    }

    if (is_wp_error($result)) {
        error_log('Unzip error: ' . $result->get_error_message());
        return $result; // Return error if unzipping fails
    }
    // Run Composer install
    $repo_dir = explode('/', $repo)[1];

    $plugin_dir = WP_PLUGIN_DIR . '/' . $repo_dir . '-'.$branch; // Adjust this based on the unzipped directory structure
    $plugin_dir = apply_filters('github_branch_tester_plugin_dir', $plugin_dir, $repo);
    putenv('COMPOSER_HOME=' . WP_CONTENT_DIR . '/composer');
    exec("cd $plugin_dir && composer install 2>&1", $output, $return_var);
    if ($return_var !== 0) {
        error_log('Composer install error: ' . implode("\n", $output));
        return new WP_Error('composer_error', 'Error running composer install: ' . implode("\n", $output));
    }

    // Run npm install
    exec("cd $plugin_dir && npm install 2>&1", $output, $return_var);
    if ($return_var !== 0) {
        error_log('NPM install error: ' . implode("\n", $output));
        return new WP_Error('npm_error', 'Error running npm install: ' . implode("\n", $output));
    }

    // Run npm run build
    exec("cd $plugin_dir && npm run build 2>&1", $output, $return_var);
    if ($return_var !== 0) {
        error_log('NPM build error: ' . implode("\n", $output));
        return new WP_Error('build_error', 'Error running npm run build: ' . implode("\n", $output));
    }

    return true; // Success
}

function createSlug($branchName) {
    // Ensure the input is a string
    if (!is_string($branchName)) {
        return $branchName;
    }

    // Replace slashes with hyphens and convert to lowercase
    $slug = str_replace('/', '-', $branchName);

    // Log the resulting slug for debugging

    return strtolower($slug);
}
