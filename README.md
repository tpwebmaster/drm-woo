# Plugin DRM para WooCommerce (drm-woo)

Plugin WordPress que aplica **DRM (Digital Rights Management)** a produtos digitais em PDF vendidos pelo WooCommerce. Ao concluir a compra, o cliente baixa o PDF protegido — com senha e um rodapé de identificação em todas as páginas — em vez do arquivo original.

- **Versão:** 1.0.0
- **Autor:** Thiago Póvoa — [desenvolvedorwp.com](https://desenvolvedorwp.com)
- **Licença:** GPL2

## Como funciona

O plugin intercepta o download nativo do WooCommerce e serve uma versão protegida do PDF, gerada sob demanda:

1. **Intercepta o download** — via filtro `woocommerce_product_file_download_path`, redireciona o pedido de download para uma rota AJAX própria (`drm_download_pdf`).
2. **Valida a requisição** — confirma que o produto é virtual/baixável, que o arquivo é um PDF existente e que o pedido está com status **concluído** (`completed`). A rota AJAX é protegida por *nonce*.
3. **Aplica o DRM** — usando TCPDF + FPDI, reimporta cada página do PDF original e:
   - Define **proteção/senha** no PDF (`SetProtection`), usando o e-mail do comprador como senha de usuário.
   - Adiciona um **rodapé de identificação** em todas as páginas: `Adquirido por {email}` (fonte Times Bold 7, cor cinza).
4. **Registra o download** — contabiliza o download no WooCommerce (`track_download`), igual ao comportamento nativo.
5. **Serve e limpa** — envia o PDF protegido como anexo e agenda a remoção dos arquivos temporários (no `shutdown` e limpando temporários com mais de 1 hora).

## Requisitos

- WordPress com **WooCommerce ativo** (o plugin aborta silenciosamente se o WooCommerce não estiver ativo).
- PHP com suporte às bibliotecas de PDF incluídas.
- Produtos configurados como **virtuais** e **baixáveis**, com arquivos **PDF**.

## Instalação

1. Copie a pasta `drm-woo` para `wp-content/plugins/`.
2. Ative o **Plugin DRM para WooCommerce** no painel do WordPress.
3. Garanta que o WooCommerce esteja ativo.

## Estrutura do projeto

```
drm-woo/
├── index.php          # Plugin principal (classe DRM_WooCommerce)
├── README.md
└── lib/               # Bibliotecas de manipulação de PDF (vendored)
    ├── tcpdf/         # Geração de PDF
    ├── fpdi/          # Importação de páginas de PDF existentes
    ├── fpdi_old/      # Versão anterior do FPDI
    └── fpdf186/       # FPDF
```

## Detalhes técnicos

- **Classe principal:** `DRM_WooCommerce` (singleton via `instance()`).
- **Hooks utilizados:**
  - `woocommerce_product_file_download_path` — intercepta o caminho de download.
  - `wp_ajax_drm_download_pdf` / `wp_ajax_nopriv_drm_download_pdf` — processa o download com DRM.
  - `shutdown` — limpeza de arquivos temporários.
- **Arquivos temporários:** gerados em `wp-upload-dir/drm_temp_*.pdf` e removidos automaticamente.

## Observações de segurança

O plugin usa *nonce* na rota AJAX e só entrega o arquivo para pedidos concluídos. A senha de proprietário do PDF (`SetProtection`) está **fixa no código** (`index.php`) — recomenda-se movê-la para uma configuração/constante antes de usar em produção.

## Licença

GPL2 — veja o cabeçalho de `index.php`.
