<?php
/*
Plugin Name: Plugin DRM para WooCommerce
Plugin URI:  https://desenvolvedorwp.com
Description: Este é um plugin para funcionamento do DRM para WooCommerce.
Version:     1.0.0
Author:      Thiago Póvoa
Author URI:  https://desenvolvedorwp.com
License:     GPL2
*/

// Se este arquivo for chamado diretamente, abortar.
if (! defined('ABSPATH')) {
    exit;
}

// Verifica se o WooCommerce está ativo
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Definindo constantes para o plugin
define('DRM_WOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DRM_WOO_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Classe principal do DRM WooCommerce
 */
class DRM_WooCommerce {
    
    /**
     * Instância única da classe
     */
    private static $_instance = null;
    
    /**
     * Arquivos processados para limpeza
     */
    public $processed_files = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'init'));
        
        // Hook principal para interceptar downloads
        add_filter('woocommerce_product_file_download_path', array($this, 'redirect_to_drm_download'), 50, 3);
        
        // Hook para processar o download com DRM
        add_action('wp_ajax_drm_download_pdf', array($this, 'handle_drm_download'));
        add_action('wp_ajax_nopriv_drm_download_pdf', array($this, 'handle_drm_download'));
        
        // Limpeza de arquivos temporários
        add_action('shutdown', array($this, 'cleanup_temp_files'));
    }
    
    /**
     * Obter instância única
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Inicialização
     */
    public function init() {
        // Carrega o domínio de texto se necessário
    }
    
    /**
     * Verifica se é um arquivo PDF
     */
    private function is_pdf_file($file_path) {
        $file_info = pathinfo($file_path);
        $file_ext = strtolower($file_info['extension'] ?? '');
        return $file_ext === 'pdf';
    }
    
    /**
     * Redireciona download para URL com DRM
     */
    public function redirect_to_drm_download($file_path, $product, $download_id) {
        
        // Verifica se estamos na tela de administração
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if (!is_null($screen) && in_array($screen->id, array('shop_order', 'shop_subscription'))) {
                return $file_path;
            }
        }
        
        // Verifica se é uma requisição de download
        if (!isset($_GET['download_file'])) {
            return $file_path;
        }
        
        // Verifica se o produto é válido
    if (!$product || !$product->is_virtual() || !$product->is_downloadable()) {
        return $file_path;
    }

        // Obter o caminho do arquivo
        $upload_dir = wp_get_upload_dir();
        $uploads_base_url = $upload_dir['baseurl'];
        $uploads_base_dir = $upload_dir['basedir'];
        
        // Converter URL para caminho local
        $original_file = str_replace($uploads_base_url, $uploads_base_dir, $file_path);
        
        // Verifica se é um arquivo PDF
        if (!$this->is_pdf_file($original_file)) {
            return $file_path;
        }
        
        // Verifica se o arquivo existe
        if (!file_exists($original_file)) {
        return $file_path;
    }

        // Obter dados do pedido
        $order_data = $this->get_order_data_from_request();
        if (!$order_data) {
        return $file_path;
    }

        // Verifica se o pedido está concluído
        $order = wc_get_order($order_data['order_id']);
        if (!$order || $order->get_status() !== 'completed') {
    return $file_path;
}

        // Cria uma URL personalizada para download com DRM
        $drm_url = admin_url('admin-ajax.php') . '?' . http_build_query(array(
            'action' => 'drm_download_pdf',
            'file' => base64_encode($original_file),
            'order_id' => $order->get_id(),
            'nonce' => wp_create_nonce('drm_download_' . $order->get_id())
        ));
        
        // Redireciona para a URL personalizada
        wp_redirect($drm_url);
        exit;
    }
    
    /**
     * Processa o download com DRM via AJAX
     */
    public function handle_drm_download() {
        // Verifica nonce
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        if (!wp_verify_nonce($_GET['nonce'] ?? '', 'drm_download_' . $order_id)) {
            wp_die('Erro de segurança: Nonce inválido.');
        }
        
        // Decodifica o caminho do arquivo
        $file_path = base64_decode($_GET['file'] ?? '');
        if (!file_exists($file_path)) {
            wp_die('Arquivo não encontrado.');
        }
        
        // Verifica se é PDF
        if (!$this->is_pdf_file($file_path)) {
            wp_die('Arquivo deve ser um PDF.');
        }
        
        // Obtém o pedido
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'completed') {
            wp_die('Pedido inválido ou não concluído.');
        }
        
        // Registra o download para contagem (igual ao WooCommerce nativo)
        $this->track_download($order, $file_path);
        
        // Aplica DRM e serve o arquivo
        $this->serve_pdf_with_drm($file_path, $order);
    }
    
    /**
     * Registra o download para contagem no WooCommerce
     */
    private function track_download($order, $file_path) {
        try {
            // Busca todas as permissões de download para este pedido
            $data_store = WC_Data_Store::load('customer-download');
            $downloads = $data_store->get_downloads(array(
                'user_email' => $order->get_billing_email(),
                'order_id' => $order->get_id()
            ));
            
            if (!empty($downloads) && is_array($downloads)) {
                foreach ($downloads as $download) {
                    // Verifica se o arquivo corresponde ao download
                    $product = wc_get_product($download->get_product_id());
                    if ($product && $product->has_file($download->get_download_id())) {
                        $file = $product->get_file($download->get_download_id());
                        $upload_dir = wp_get_upload_dir();
                        $file_url = $file->get_file();
                        $file_local_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
                        
                        if ($file_local_path === $file_path) {
                            // Registra o download
                            $user_id = $order->get_customer_id();
                            $user_ip = !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
                            $download->track_download($user_id, $user_ip);
                            break;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('DRM WooCommerce: Erro ao registrar download - ' . $e->getMessage());
        }
    }
    
    /**
     * Serve o PDF com DRM aplicado
     */
    private function serve_pdf_with_drm($original_file, $order) {
        // Inicia o buffer de saída
        ob_start();
        
        try {
            // Verifica se as bibliotecas existem
            if (!file_exists(DRM_WOO_PLUGIN_DIR . 'lib/tcpdf/tcpdf.php')) {
                ob_end_clean();
                wp_die('Erro: Biblioteca TCPDF não encontrada.');
            }
            
            if (!file_exists(DRM_WOO_PLUGIN_DIR . 'lib/fpdi/src/autoload.php')) {
                ob_end_clean();
                wp_die('Erro: Biblioteca FPDI não encontrada.');
            }
            
            // Carrega as bibliotecas
            require_once DRM_WOO_PLUGIN_DIR . 'lib/tcpdf/tcpdf.php';
            require_once DRM_WOO_PLUGIN_DIR . 'lib/fpdi/src/autoload.php';
            
            // Informações do usuário
            $user_email = $order->get_billing_email();
            $user_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            
            // Instancia a classe FPDI
            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();

            // Define a senha do PDF como o e-mail do usuário
            $pdf->SetProtection(['copy'], $user_email, 'supersecreta123');

            // Obtém o número de páginas do PDF original
            $pageCount = $pdf->setSourceFile($original_file);

            // Adiciona as páginas do PDF original com informações de DRM no rodapé
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
                
                // Desabilita o page break automático temporariamente
                $pdf->SetAutoPageBreak(false);
                
                // Posiciona o rodapé no final absoluto da página
                $pageHeight = $size['height'];
                $footerY = $pageHeight - 5; // 5mm do final da página
                
                // Configura a fonte e cor
                $pdf->SetFont('times', 'B', 7); // Fonte Times, Bold, tamanho 7
                $pdf->SetTextColor(150, 150, 150); // Cor #969696
                
                // Posiciona o rodapé de forma absoluta
                $pdf->SetXY(0, $footerY);
                $pdf->Cell(0, 5, "Adquirido por {$user_email}", 0, 0, 'C');
                
                // Reabilita o page break automático
                $pdf->SetAutoPageBreak(true, 15);
            }
            
            // Gera um arquivo temporário único
            $upload_dir = wp_upload_dir();
            $temp_file = $upload_dir['basedir'] . '/drm_temp_' . uniqid() . '_' . time() . '.pdf';
            
            // Salva o PDF modificado
            $pdf->Output($temp_file, 'F');
            
            // Limpa qualquer saída anterior
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Define os headers para download
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . basename($original_file) . '"');
            header('Content-Length: ' . filesize($temp_file));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Serve o arquivo
            readfile($temp_file);
            
            // Adiciona à lista para limpeza
            $this->processed_files[] = $temp_file;
            
            exit;
            
        } catch (Exception $e) {
            // Limpa o buffer em caso de erro
            while (ob_get_level()) {
                ob_end_clean();
            }
            wp_die('Erro ao gerar PDF: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtém dados do pedido da requisição
     */
    private function get_order_data_from_request() {
        $download_file = isset($_GET['download_file']) ? sanitize_text_field($_GET['download_file']) : '';
        $order_key = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : '';
        $email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if (empty($download_file) || empty($order_key)) {
            return false;
        }
        
        // Buscar pedido pelo order_key
        $orders = wc_get_orders(array(
            'limit' => 1,
            'order_key' => $order_key,
            'status' => array('completed')
        ));
        
        if (empty($orders)) {
            return false;
        }
        
        $order = $orders[0];
        
        return array(
            'order_id' => $order->get_id(),
            'order_key' => $order_key,
            'email' => $email,
            'download_id' => $download_file
        );
    }
    
    /**
     * Limpa arquivos temporários
     */
    public function cleanup_temp_files() {
        foreach ($this->processed_files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        // Limpa arquivos DRM antigos (mais de 1 hora)
        $upload_dir = wp_upload_dir();
        $pattern = $upload_dir['basedir'] . '/drm_temp_*.pdf';
        $files = glob($pattern);
        
        if ($files) {
            foreach ($files as $file) {
                if (file_exists($file) && (time() - filemtime($file)) > 3600) {
                    @unlink($file);
                }
            }
        }
    }
}

// Inicializa o plugin
DRM_WooCommerce::instance();