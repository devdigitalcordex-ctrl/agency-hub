<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$settings     = Agency_Hub::get_settings();
$hub_url      = $settings['hub_url']       ?? '';
$site_key     = $settings['site_key']      ?? '';
$connected    = $settings['connected']     ?? false;
$last_hb      = $settings['last_heartbeat'] ?? null;
$site_url     = get_site_url();

// ── SAVE SETTINGS ────────────────────────────────────────────
if ( isset( $_POST['agency_hub_save'] ) && check_admin_referer( 'agency_hub_settings' ) ) {
    $new_hub_url  = esc_url_raw( trim( $_POST['hub_url']  ?? '' ) );
    $new_site_key = sanitize_text_field( trim( $_POST['site_key'] ?? '' ) );

    Agency_Hub::update_setting( 'hub_url',  $new_hub_url );
    Agency_Hub::update_setting( 'site_key', $new_site_key );
    Agency_Hub::update_setting( 'connected', false );

    $hub_url  = $new_hub_url;
    $site_key = $new_site_key;
    echo '<div class="ah-notice ah-notice--success">Settings saved. The site will connect on the next heartbeat (within 5 minutes).</div>';
}

// ── TEST CONNECTION MANUALLY ─────────────────────────────────
if ( isset( $_POST['agency_hub_test'] ) && check_admin_referer( 'agency_hub_settings' ) ) {
    Agency_Hub_Heartbeat::send();
    $settings  = Agency_Hub::get_settings();
    $connected = $settings['connected'] ?? false;
    if ( $connected ) {
        echo '<div class="ah-notice ah-notice--success">Connection successful! Site is now showing as Online in the Hub.</div>';
    } else {
        echo '<div class="ah-notice ah-notice--error">Connection failed. Check your Hub URL and Site Key are correct.</div>';
    }
}

$is_configured = ! empty( $hub_url ) && ! empty( $site_key );
?>
<div class="ah-wrap">

    <!-- HEADER -->
    <div class="ah-header">
        <div class="ah-header__logo">
            <img src="https://digitalcordex.com/wp-content/uploads/2024/10/Untitled-1.png" alt="Digital Cordex" />
        </div>
        <div class="ah-header__title">
            <h1>Agency Hub</h1>
            <span class="ah-version">v<?php echo AGENCY_HUB_VERSION; ?></span>
        </div>
        <div class="ah-header__status">
            <?php if ( $connected ) : ?>
                <span class="ah-badge ah-badge--connected">
                    <span class="ah-badge__dot"></span>
                    Connected
                </span>
            <?php elseif ( $is_configured ) : ?>
                <span class="ah-badge ah-badge--pending">
                    <span class="ah-badge__dot"></span>
                    Connecting...
                </span>
            <?php else : ?>
                <span class="ah-badge ah-badge--disconnected">
                    <span class="ah-badge__dot"></span>
                    Not Configured
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- TABS -->
    <div class="ah-tabs">
        <button class="ah-tab ah-tab--active" data-tab="connection">Connection</button>
        <button class="ah-tab" data-tab="status">Site Status</button>
        <button class="ah-tab" data-tab="security">Security</button>
        <button class="ah-tab" data-tab="logs">Recent Logs</button>
    </div>

    <!-- TAB: CONNECTION -->
    <div class="ah-panel ah-panel--active" id="ah-tab-connection">
        <div class="ah-card">
            <div class="ah-card__header">
                <h2>Connect to Agency Hub</h2>
                <p>Add this site to your Agency Hub dashboard, then paste the Site Key below.</p>
            </div>

            <div class="ah-card__body">

                <?php if ( $connected ) : ?>
                <div class="ah-banner ah-banner--success">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    Connected to <strong><?php echo esc_html( $hub_url ); ?></strong>
                    <?php if ( $last_hb ) : ?>
                    &nbsp;&mdash; Last heartbeat: <strong><?php echo esc_html( $last_hb ); ?></strong>
                    <?php endif; ?>
                </div>
                <?php elseif ( $is_configured ) : ?>
                <div class="ah-banner ah-banner--info">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    Settings saved. Connection will establish within 5 minutes, or click Test Connection below.
                </div>
                <?php else : ?>
                <div class="ah-banner ah-banner--info">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <strong>How to connect:</strong> Go to your Agency Hub dashboard &rarr; Add Site &rarr; copy the Site Key &rarr; paste it below.
                </div>
                <?php endif; ?>

                <!-- FORM -->
                <form method="post" class="ah-form" style="margin-top: 24px;">
                    <?php wp_nonce_field( 'agency_hub_settings' ); ?>

                    <div class="ah-field-group">

                        <div class="ah-field">
                            <label class="ah-label">Hub URL</label>
                            <div class="ah-input-wrap">
                                <input
                                    type="url"
                                    name="hub_url"
                                    value="<?php echo esc_attr( $hub_url ); ?>"
                                    placeholder="https://agency-hub-gamma.vercel.app"
                                    class="ah-input"
                                    required
                                />
                            </div>
                            <p class="ah-help">The URL of your Agency Hub dashboard deployment.</p>
                        </div>

                        <div class="ah-field">
                            <label class="ah-label">Site Key (from Hub)</label>
                            <div class="ah-input-wrap">
                                <input
                                    type="text"
                                    name="site_key"
                                    value="<?php echo esc_attr( $site_key ); ?>"
                                    placeholder="Paste the Site Key shown in the Hub dashboard"
                                    class="ah-input ah-input--mono"
                                    required
                                />
                            </div>
                            <p class="ah-help">Found in Agency Hub &rarr; your site &rarr; Plugin Connection Key field.</p>
                        </div>

                    </div>

                    <div class="ah-actions">
                        <button type="submit" name="agency_hub_save" class="ah-btn ah-btn--primary">
                            Save Settings
                        </button>
                        <?php if ( $is_configured ) : ?>
                        <button type="submit" name="agency_hub_test" class="ah-btn ah-btn--secondary">
                            Test Connection Now
                        </button>
                        <?php endif; ?>
                    </div>
                </form>

            </div>
        </div>

        <!-- SITE URL INFO -->
        <div class="ah-card" style="margin-top: 16px;">
            <div class="ah-card__body">
                <p class="ah-help" style="margin: 0;">
                    <strong>This site&apos;s URL:</strong>
                    <code><?php echo esc_html( $site_url ); ?></code>
                    &mdash; make sure this matches what you entered in the Hub when adding the site.
                </p>
            </div>
        </div>
    </div>

    <!-- TAB: STATUS -->
    <div class="ah-panel" id="ah-tab-status">
        <div class="ah-card">
            <div class="ah-card__header"><h2>Site Status</h2></div>
            <div class="ah-card__body">
                <?php
                $scan_status   = Agency_Hub::get_setting( 'last_scan_status', 'Never run' );
                $scan_at       = Agency_Hub::get_setting( 'last_scan_at', null );
                $backup_status = Agency_Hub::get_setting( 'last_backup_status', 'Never run' );
                $backup_at     = Agency_Hub::get_setting( 'last_backup', null );
                ?>
                <table class="ah-status-table">
                    <tr>
                        <td class="ah-status-label">WordPress Version</td>
                        <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
                    </tr>
                    <tr>
                        <td class="ah-status-label">PHP Version</td>
                        <td><?php echo esc_html( phpversion() ); ?></td>
                    </tr>
                    <tr>
                        <td class="ah-status-label">Plugin Version</td>
                        <td><?php echo esc_html( AGENCY_HUB_VERSION ); ?></td>
                    </tr>
                    <tr>
                        <td class="ah-status-label">Last Heartbeat</td>
                        <td><?php echo $last_hb ? esc_html( $last_hb ) : 'Not yet'; ?></td>
                    </tr>
                    <tr>
                        <td class="ah-status-label">Last Scan</td>
                        <td><?php echo esc_html( $scan_status ) . ( $scan_at ? ' &mdash; ' . esc_html( $scan_at ) : '' ); ?></td>
                    </tr>
                    <tr>
                        <td class="ah-status-label">Last Backup</td>
                        <td><?php echo esc_html( $backup_status ) . ( $backup_at ? ' &mdash; ' . esc_html( $backup_at ) : '' ); ?></td>
                    </tr>
                    <tr>
                        <td class="ah-status-label">Active Plugins</td>
                        <td><?php echo count( get_option( 'active_plugins', array() ) ); ?></td>
                    </tr>
                    <tr>
                        <td class="ah-status-label">Memory Usage</td>
                        <td><?php echo esc_html( size_format( memory_get_usage( true ) ) ); ?> / <?php echo esc_html( ini_get( 'memory_limit' ) ); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB: SECURITY -->
    <div class="ah-panel" id="ah-tab-security">
        <div class="ah-card">
            <div class="ah-card__header">
                <h2>Security Scan</h2>
                <p>Runs automatically every 24 hours. You can also trigger a scan from the Hub dashboard.</p>
            </div>
            <div class="ah-card__body">
                <?php
                $last_scan   = Agency_Hub::get_setting( 'last_scan_at' );
                $last_status = Agency_Hub::get_setting( 'last_scan_status', 'never' );
                $findings    = Agency_Hub::get_setting( 'last_scan_findings', array() );
                ?>
                <div class="ah-scan-summary">
                    <div class="ah-scan-stat">
                        <div class="ah-scan-stat__value <?php echo $last_status === 'threats_found' ? 'ah-scan-stat__value--danger' : 'ah-scan-stat__value--safe'; ?>">
                            <?php echo count( $findings ); ?>
                        </div>
                        <div class="ah-scan-stat__label">Threats Found</div>
                    </div>
                    <div class="ah-scan-stat">
                        <div class="ah-scan-stat__value"><?php echo esc_html( $last_scan ?? 'Never' ); ?></div>
                        <div class="ah-scan-stat__label">Last Scan</div>
                    </div>
                </div>

                <?php if ( ! empty( $findings ) ) : ?>
                <div class="ah-findings">
                    <h3>Findings</h3>
                    <?php foreach ( $findings as $finding ) : ?>
                    <div class="ah-finding ah-finding--<?php echo esc_attr( $finding['severity'] ?? 'medium' ); ?>">
                        <strong><?php echo esc_html( $finding['threat'] ?? $finding['type'] ?? 'Unknown' ); ?></strong>
                        <code><?php echo esc_html( $finding['file'] ?? '' ); ?></code>
                        <?php if ( ! empty( $finding['action'] ) ) : ?>
                        <span class="ah-finding__action"><?php echo esc_html( $finding['action'] ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else : ?>
                <p class="ah-clean-msg">No threats found in last scan.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="ah-card" style="margin-top: 16px;">
            <div class="ah-card__header"><h2>IP Blocking Mode</h2></div>
            <div class="ah-card__body">
                <?php
                $allowlist_mode = Agency_Hub::get_setting( 'allowlist_mode', false );
                ?>
                <p>
                    Current mode: <strong><?php echo $allowlist_mode ? 'Allowlist (only listed IPs can access)' : 'Blocklist (blocking specific IPs)'; ?></strong>
                </p>
                <p class="ah-help">Manage IP rules from the Agency Hub dashboard.</p>
            </div>
        </div>
    </div>

    <!-- TAB: LOGS -->
    <div class="ah-panel" id="ah-tab-logs">
        <div class="ah-card">
            <div class="ah-card__header"><h2>Recent Activity</h2></div>
            <div class="ah-card__body">
                <?php
                global $wpdb;
                $log_table = $wpdb->prefix . AGENCY_HUB_LOG_TABLE;
                $logs = $wpdb->get_results(
                    "SELECT * FROM {$log_table} ORDER BY occurred_at DESC LIMIT 50",
                    ARRAY_A
                );
                ?>
                <?php if ( empty( $logs ) ) : ?>
                    <p class="ah-help">No activity logs yet.</p>
                <?php else : ?>
                <table class="ah-logs-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Severity</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $logs as $log ) : ?>
                        <tr class="ah-log-row ah-log-row--<?php echo esc_attr( $log['severity'] ); ?>">
                            <td><?php echo esc_html( $log['occurred_at'] ); ?></td>
                            <td><?php echo esc_html( $log['event_type'] ); ?></td>
                            <td><span class="ah-severity ah-severity--<?php echo esc_attr( $log['severity'] ); ?>"><?php echo esc_html( $log['severity'] ); ?></span></td>
                            <td><?php echo esc_html( mb_strimwidth( $log['message'], 0, 120, '...' ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
