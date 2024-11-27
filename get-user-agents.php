<?php
/*
Plugin Name: Get User-Agents
Description: This plugin records the information of all User Agents visiting the website. The information is stored in the database for later extraction and analysis. The data recorded are: IP, User Agent, Time spent on the site, Date and time. This information is very useful for solving various problems related to web traffic (mainly SEO).
Requires at least: 3.0
Requires PHP: 5.6
Version: 1.0.4.1
Author: Dragondeluz
Author URI: https://profiles.wordpress.org/dragondeluz
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
 

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


// Crear tabla para almacenar la información del usuario
register_activation_hook(__FILE__, 'sgtgua_create_user_tracking_table');

function sgtgua_create_user_tracking_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_tracking';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_ip varchar(40) NOT NULL,
        user_agent varchar(255) NOT NULL,
        time_on_site int(11) NOT NULL,
        date_time datetime NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Registrar información del usuario en la base de datos
add_action('wp_footer', 'sgtgua_register_user_data');

function sgtgua_register_user_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_tracking';
    $user_ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
    $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT']);
    $date_time = current_time('mysql');
    $wpdb->insert(
        $table_name,
        array(
            'user_ip' => $user_ip,
            'user_agent' => $user_agent,
            'date_time' => $date_time
        )
    );
}

// Mostrar lista de registros en una opción del menú Herramientas
add_action('admin_menu', 'sgtgua_add_user_tracking_menu');

function sgtgua_add_user_tracking_menu() {
    add_management_page( 'Get User-agents', 'Get User-agents', 'manage_options', 'user-tracking', 'sgtgua_display_user_tracking_list' );
}

function sgtgua_display_user_tracking_list() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_tracking';
    $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

    // Obtenemos el valor por defecto para los días
    $days = isset($_POST['preserve_days']) ? intval($_POST['preserve_days']) : 30;
    $keep_csv = isset($_POST['keep_csv']) ? sanitize_text_field($_POST['keep_csv']) : false;
    ?>
    <div class="wrap">
        <h1>Lista de registros de usuarios</h1><br/>
        <div>
        <h3>Borrar Datos:</h3>
        <form method="post" style="display:inline-block; margin-left: 10px;">
            <?php wp_nonce_field('sgtgua_clean_old_user_data_nonce', 'sgtgua_clean_old_user_data_nonce_field'); ?>
            <label for="preserve_days">De más de</label>
            <input type="number" name="preserve_days" value="<?php echo esc_attr($days); ?>" style="width: 50px;">
            <label for="preserve_days">días</label>
            <input type="submit" class="button-secondary" value="Limpiar registros antiguos">
            <br/>
        </form>
        <h3>Exportar Datos:</h3>
        <form method="post" style="display:inline-block; margin-left: 10px;">
            <?php wp_nonce_field('export_csv_nonce', 'export_csv_nonce_field'); ?>
            <input type="hidden" name="export_csv" value="1">
            <input type="submit" class="button-secondary" value="Exportar a CSV">
        </form>
        </div><br/>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>IP</th>
                    <th>User Agent</th>
                    <th>Fecha y hora</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!empty($results)) {
                    foreach ($results as $row) {
                        echo '<tr>';
                        echo '<td>' . esc_html($row['id']) . '</td>';
                        echo '<td>' . esc_html($row['user_ip']) . '</td>';
                        echo '<td>' . esc_html($row['user_agent']) . '</td>';
                        echo '<td>' . esc_html($row['date_time']) . '</td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="5">No hay registros</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Función para borrar registros antiguos
function sgtgua_clean_old_user_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_tracking';
    
    if (isset($_POST['preserve_days']) && check_admin_referer('sgtgua_clean_old_user_data_nonce', 'sgtgua_clean_old_user_data_nonce_field')) {
        $days = intval($_POST['preserve_days']);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));

        // Eliminar registros más antiguos que el valor de días proporcionado
        $wpdb->query(
            $wpdb->prepare("DELETE FROM $table_name WHERE date_time < %s", $cutoff_date)
        );
    }
}
add_action('admin_init', 'sgtgua_clean_old_user_data');

// Exportar datos en formato CSV
add_action('admin_init', 'sgtgua_export_data_to_csv');

function sgtgua_export_data_to_csv() {
    global $wpdb;
    if (isset($_POST['export_csv']) && check_admin_referer('export_csv_nonce', 'export_csv_nonce_field')) {
        $table_name = $wpdb->prefix . 'user_tracking';
        $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

        if (!empty($results)) {
            $file_name = 'user-tracking-' . time() . '.csv';
            $file = fopen(plugin_dir_path(__FILE__) . $file_name, 'w');

            fputcsv($file, array('ID', 'IP', 'User Agent', 'Tiempo en el sitio', 'Fecha y hora'));

            foreach ($results as $row) {
                fputcsv($file, $row);
            }
            fclose($file);

            header("Content-type: text/csv");
            header("Content-disposition: attachment; filename=" . $file_name);
            readfile(plugin_dir_path(__FILE__) . $file_name);

            // Eliminar CSV solo si no se marcó la opción para mantenerlo
            if (!isset($_POST['keep_csv'])) {
                unlink(plugin_dir_path(__FILE__) . $file_name);
            }

            exit();
        }
    }
}