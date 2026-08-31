<?php
/**
 * Archived Products - keep inactive product URLs accessible for SEO.
 *
 * @author    kopolot
 * @copyright kopolot
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_Archivedproducts extends Module
{
    /** @var bool Prevents recursive updates from product hooks */
    private static $isUpdatingProduct = false;

    /** @var bool Prevents rendering the archived banner multiple times on one page */
    private static $bannerRendered = false;

    public const CONFIG_AUTO_ARCHIVE = 'PS_ARCHIVEDPRODUCTS_AUTO_REDIRECT';
    public const CONFIG_SHOW_BANNER = 'PS_ARCHIVEDPRODUCTS_SHOW_BANNER';
    public const CONFIG_HIDE_PRICE = 'PS_ARCHIVEDPRODUCTS_HIDE_PRICE';
    public const CONFIG_CUSTOM_MESSAGE = 'PS_ARCHIVEDPRODUCTS_CUSTOM_MESSAGE';

    public function __construct()
    {
        $this->name = 'ps_archivedproducts';
        $this->tab = 'seo';
        $this->version = '1.1.4';
        $this->author = 'kopolot';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Archived Products');
        $this->description = $this->l(
            'Keeps archived product pages online for SEO while blocking orders. Products stay enabled but hidden from listings.'
        );
        $this->ps_versions_compliancy = [
            'min' => '1.7.1.0',
            'max' => _PS_VERSION_,
        ];
    }

    public function install()
    {
        return parent::install()
            && $this->installDatabase()
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionProductSave')
            && $this->registerHook('actionObjectProductUpdateAfter')
            && $this->registerHook('actionPresentProduct')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayProductPriceBlock')
            && $this->registerHook('displayReassurance')
            && $this->registerHook('filterProductContent')
            && $this->installConfiguration();
    }

    public function uninstall()
    {
        return $this->uninstallConfiguration()
            && $this->uninstallDatabase()
            && parent::uninstall();
    }

    public function installDatabase()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'archivedproducts` (
                `id_product` INT(10) UNSIGNED NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_product`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    private function uninstallDatabase()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'archivedproducts`');
    }

    private function installConfiguration()
    {
        $languages = Language::getLanguages(false);
        $defaultMessages = [];

        foreach ($languages as $language) {
            $defaultMessages[(int) $language['id_lang']] = $this->getDefaultMessageForIso($language['iso_code']);
        }

        return Configuration::updateValue(self::CONFIG_AUTO_ARCHIVE, 1)
            && Configuration::updateValue(self::CONFIG_SHOW_BANNER, 1)
            && Configuration::updateValue(self::CONFIG_HIDE_PRICE, 0)
            && Configuration::updateValue(self::CONFIG_CUSTOM_MESSAGE, $defaultMessages, true);
    }

    private function uninstallConfiguration()
    {
        return Configuration::deleteByName(self::CONFIG_AUTO_ARCHIVE)
            && Configuration::deleteByName(self::CONFIG_SHOW_BANNER)
            && Configuration::deleteByName(self::CONFIG_HIDE_PRICE)
            && Configuration::deleteByName(self::CONFIG_CUSTOM_MESSAGE);
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitPsArchivedProducts')) {
            $output .= $this->processConfigurationForm();
        }

        if (Tools::isSubmit('submitPsArchivedProductsMigrate')) {
            $output .= $this->migrateExistingInactiveProducts();
        }

        return $output . $this->renderConfigurationForm();
    }

    private function processConfigurationForm()
    {
        $languages = Language::getLanguages(false);
        $messages = [];

        foreach ($languages as $language) {
            $idLang = (int) $language['id_lang'];
            $messages[$idLang] = (string) Tools::getValue(
                self::CONFIG_CUSTOM_MESSAGE . '_' . $idLang,
                $this->getDefaultMessageForIso($language['iso_code'])
            );
        }

        Configuration::updateValue(self::CONFIG_AUTO_ARCHIVE, (int) Tools::getValue(self::CONFIG_AUTO_ARCHIVE));
        Configuration::updateValue(self::CONFIG_SHOW_BANNER, (int) Tools::getValue(self::CONFIG_SHOW_BANNER));
        Configuration::updateValue(self::CONFIG_HIDE_PRICE, (int) Tools::getValue(self::CONFIG_HIDE_PRICE));
        Configuration::updateValue(self::CONFIG_CUSTOM_MESSAGE, $messages, true);

        return $this->displayConfirmation($this->l('Settings updated.'));
    }

    private function migrateExistingInactiveProducts()
    {
        $updated = $this->applyArchivedRedirectToInactiveProducts();

        return $this->displayConfirmation(
            sprintf($this->l('%d products converted to archived mode.'), $updated)
        );
    }

    private function renderConfigurationForm()
    {
        $languages = Language::getLanguages(false);
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Archived products settings'),
                    'icon' => 'icon-archive',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Auto-archive on deactivation'),
                        'name' => self::CONFIG_AUTO_ARCHIVE,
                        'desc' => $this->l(
                            'When you deactivate a product, archive it instead: the page stays online (HTTP 200), the product is hidden from listings and cannot be ordered.'
                        ),
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'auto_archive_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'auto_archive_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Show archived banner'),
                        'name' => self::CONFIG_SHOW_BANNER,
                        'desc' => $this->l('Display a visible notice on archived product pages.'),
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'show_banner_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'show_banner_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Hide prices on archived products'),
                        'name' => self::CONFIG_HIDE_PRICE,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'hide_price_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'hide_price_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Archived product message'),
                        'name' => self::CONFIG_CUSTOM_MESSAGE,
                        'lang' => true,
                        'desc' => $this->l('Message shown on archived product pages.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPsArchivedProducts';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigurationFormValues($languages),
            'languages' => $languages,
            'id_language' => $defaultLang,
        ];

        $info = '<div class="alert alert-info">' . $this->l(
            'Archived products remain enabled (active) but use visibility "Nowhere", so they are hidden from the catalog while the direct URL still works.'
        ) . '</div>';

        $migrateForm = '
            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-refresh"></i> ' . $this->l('Migrate existing products') . '
                </div>
                <div class="panel-body">
                    <p>' . $this->l(
                        'Convert inactive products (404 redirect) to archived mode.'
                    ) . '</p>
                    <form method="post" action="' . $helper->currentIndex . '&token=' . $helper->token . '">
                        <button type="submit" name="submitPsArchivedProductsMigrate" class="btn btn-primary">
                            <i class="icon-refresh"></i> ' . $this->l('Update inactive products') . '
                        </button>
                    </form>
                </div>
            </div>';

        return $info . $helper->generateForm([$fieldsForm]) . $migrateForm;
    }

    private function getConfigurationFormValues(array $languages)
    {
        $values = [
            self::CONFIG_AUTO_ARCHIVE => (int) Configuration::get(self::CONFIG_AUTO_ARCHIVE),
            self::CONFIG_SHOW_BANNER => (int) Configuration::get(self::CONFIG_SHOW_BANNER),
            self::CONFIG_HIDE_PRICE => (int) Configuration::get(self::CONFIG_HIDE_PRICE),
        ];

        foreach ($languages as $language) {
            $idLang = (int) $language['id_lang'];
            $values[self::CONFIG_CUSTOM_MESSAGE][$idLang] = Configuration::get(
                self::CONFIG_CUSTOM_MESSAGE,
                $idLang,
                null,
                null,
                $this->getDefaultMessageForIso($language['iso_code'])
            );
        }

        return $values;
    }

    public function hookActionProductSave($params)
    {
        $this->handleProductArchiveState($params);
    }

    public function hookActionProductUpdate($params)
    {
        $this->handleProductArchiveState($params);
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        if (empty($params['object']) || !($params['object'] instanceof Product)) {
            return;
        }

        $this->handleProductArchiveState([
            'product' => $params['object'],
            'id_product' => (int) $params['object']->id,
        ]);
    }

    private function handleProductArchiveState($params)
    {
        if (self::$isUpdatingProduct || !(int) Configuration::get(self::CONFIG_AUTO_ARCHIVE)) {
            return;
        }

        $product = $this->resolveProductFromHookParams($params);
        if (!$product) {
            return;
        }

        $idProduct = (int) $product->id;

        if ($idProduct <= 0) {
            return;
        }

        $storedState = $this->getProductArchiveStateFromDb($idProduct);
        if ($storedState === null) {
            return;
        }

        if ($this->isProductMarkedArchived($idProduct)) {
            if ($storedState['visibility'] !== 'none' && (int) $storedState['available_for_order'] === 1) {
                $this->restoreFromArchive($idProduct);
            }

            return;
        }

        if (!(int) $storedState['active']) {
            $this->archiveProductById($idProduct);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return Product|null
     */
    private function resolveProductFromHookParams($params)
    {
        if (!empty($params['product']) && $params['product'] instanceof Product) {
            return $params['product'];
        }

        if (!empty($params['id_product'])) {
            $product = new Product((int) $params['id_product']);

            return Validate::isLoadedObject($product) ? $product : null;
        }

        return null;
    }

    /**
     * @return array{active: int, visibility: string, available_for_order: int, redirect_type: string}|null
     */
    private function getProductArchiveStateFromDb($idProduct)
    {
        $idShop = (int) $this->context->shop->id;
        $row = Db::getInstance()->getRow(
            'SELECT ps.active, ps.visibility, ps.available_for_order, ps.redirect_type
            FROM `' . _DB_PREFIX_ . 'product_shop` ps
            WHERE ps.id_product = ' . (int) $idProduct . '
            AND ps.id_shop = ' . $idShop
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'active' => (int) $row['active'],
            'visibility' => (string) $row['visibility'],
            'available_for_order' => (int) $row['available_for_order'],
            'redirect_type' => (string) $row['redirect_type'],
        ];
    }

    public function hookDisplayHeader()
    {
        if (!$this->isProductControllerWithArchivedProduct()) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'ps-archivedproducts-front',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 150]
        );
    }

    public function hookDisplayProductAdditionalInfo($params)
    {
        return $this->renderArchivedBanner($params);
    }

    public function hookDisplayProductPriceBlock($params)
    {
        if (empty($params['type']) || $params['type'] !== 'after_price') {
            return '';
        }

        return $this->renderArchivedBanner($params);
    }

    public function hookDisplayReassurance($params)
    {
        if (!(int) Configuration::get(self::CONFIG_HIDE_PRICE)) {
            return '';
        }

        return $this->renderArchivedBanner($params);
    }

    public function hookActionPresentProduct($params)
    {
        if (empty($params['presentedProduct']) || !is_object($params['presentedProduct'])) {
            return;
        }

        $presentedProduct = $params['presentedProduct'];
        if (!method_exists($presentedProduct, 'jsonSerialize') || !method_exists($presentedProduct, 'appendArray')) {
            return;
        }

        $product = $presentedProduct->jsonSerialize();
        if (!is_array($product) || !$this->isArchivedProductData($product)) {
            return;
        }

        $presentedProduct->appendArray($this->applyArchivedProductPresentation($product));
    }

    public function hookFilterProductContent($params)
    {
        if (empty($params['object']) || !is_array($params['object'])) {
            return $params;
        }

        if (!$this->isArchivedProductData($params['object'])) {
            return $params;
        }

        $params['object'] = $this->applyArchivedProductPresentation($params['object']);

        return $params;
    }

    private function archiveProduct(Product $product)
    {
        $this->archiveProductById((int) $product->id);
    }

    private function archiveProductById($idProduct)
    {
        $this->applySoftArchive((int) $idProduct);
    }

    private function applySoftArchive($idProduct)
    {
        self::$isUpdatingProduct = true;

        $data = [
            'active' => 1,
            'visibility' => 'none',
            'available_for_order' => 0,
            'id_type_redirected' => 0,
        ];

        if (version_compare(_PS_VERSION_, '8.0.0', '>=')) {
            $data['redirect_type'] = 'default';
        } else {
            $data['redirect_type'] = '404';
        }

        Db::getInstance()->update('product', $data, 'id_product = ' . (int) $idProduct);
        Db::getInstance()->update('product_shop', $data, 'id_product = ' . (int) $idProduct);

        $this->markProductAsArchived((int) $idProduct);

        self::$isUpdatingProduct = false;
    }

    private function restoreFromArchive($idProduct)
    {
        self::$isUpdatingProduct = true;

        $data = [
            'active' => 1,
            'visibility' => 'both',
            'available_for_order' => 1,
        ];

        Db::getInstance()->update('product', $data, 'id_product = ' . (int) $idProduct);
        Db::getInstance()->update('product_shop', $data, 'id_product = ' . (int) $idProduct);

        $this->unmarkProductAsArchived((int) $idProduct);

        self::$isUpdatingProduct = false;
    }

    private function markProductAsArchived($idProduct)
    {
        if ($this->isProductMarkedArchived($idProduct)) {
            return;
        }

        Db::getInstance()->insert('archivedproducts', [
            'id_product' => (int) $idProduct,
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    private function unmarkProductAsArchived($idProduct)
    {
        Db::getInstance()->delete('archivedproducts', 'id_product = ' . (int) $idProduct);
    }

    private function isProductMarkedArchived($idProduct)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT id_product FROM `' . _DB_PREFIX_ . 'archivedproducts` WHERE id_product = ' . (int) $idProduct
        );
    }

    private function applyArchivedRedirectToInactiveProducts()
    {
        $shopId = (int) $this->context->shop->id;

        $rows = Db::getInstance()->executeS(
            'SELECT ps.id_product
            FROM `' . _DB_PREFIX_ . 'product_shop` ps
            LEFT JOIN `' . _DB_PREFIX_ . 'archivedproducts` ap ON ap.id_product = ps.id_product
            WHERE ps.id_shop = ' . $shopId . '
                AND ap.id_product IS NULL
                AND (
                    ps.active = 0
                    OR (ps.active = 1 AND ps.visibility = "none" AND ps.available_for_order = 0)
                )'
        );

        if (!is_array($rows)) {
            return 0;
        }

        $updated = 0;
        foreach ($rows as $row) {
            $this->applySoftArchive((int) $row['id_product']);
            ++$updated;
        }

        return $updated;
    }

    private function applyArchivedProductPresentation(array $product)
    {
        $product['is_archived'] = true;
        $product['archived_message'] = $this->getArchivedMessage();
        $product['add_to_cart_url'] = '';
        $product['available_for_order'] = false;
        $product['availability'] = 'discontinued';
        $product['availability_message'] = $this->l('This product is no longer available for sale.');
        $product['show_availability'] = true;

        if ((int) Configuration::get(self::CONFIG_HIDE_PRICE)) {
            $product['show_price'] = false;
            $product['price'] = '';
            $product['price_amount'] = 0;
            $product['price_tax_exc'] = 0;
            $product['price_without_reduction'] = 0;
            $product['regular_price'] = '';
            $product['discount_percentage'] = '';
            $product['discount_percentage_absolute'] = '';
            $product['discount_amount'] = '';
            $product['discount_amount_to_display'] = '';
            $product['unit_price'] = '';
            $product['unit_price_full'] = '';
        }

        return $product;
    }

    private function isProductControllerWithArchivedProduct()
    {
        if (!($this->context->controller instanceof ProductController)) {
            return false;
        }

        $product = $this->context->controller->getProduct();

        return $product instanceof Product && $this->isArchivedProductData($product);
    }

    private function isArchivedProductFromParams($params)
    {
        if (!empty($params['product'])) {
            return $this->isArchivedProductData($params['product']);
        }

        return $this->isProductControllerWithArchivedProduct();
    }

    private function isArchivedProductData($product)
    {
        $idProduct = $this->resolveProductId($product);

        return $idProduct > 0 && $this->isProductArchivedForDisplay($idProduct);
    }

    /**
     * @param array<string, mixed>|object $product
     */
    private function resolveProductId($product)
    {
        if (is_array($product)) {
            if (isset($product['id_product'])) {
                return (int) $product['id_product'];
            }

            if (isset($product['id'])) {
                return (int) $product['id'];
            }

            return 0;
        }

        if (!is_object($product)) {
            return 0;
        }

        if (isset($product->id_product)) {
            return (int) $product->id_product;
        }

        if (isset($product->id)) {
            return (int) $product->id;
        }

        if (method_exists($product, 'jsonSerialize')) {
            $data = $product->jsonSerialize();

            if (is_array($data)) {
                return $this->resolveProductId($data);
            }
        }

        return 0;
    }

    private function isProductArchivedForDisplay($idProduct)
    {
        if ($this->isProductMarkedArchived($idProduct)) {
            return true;
        }

        $storedState = $this->getProductArchiveStateFromDb($idProduct);

        return $storedState !== null
            && (int) $storedState['active'] === 1
            && $storedState['visibility'] === 'none'
            && (int) $storedState['available_for_order'] === 0;
    }

    private function renderArchivedBanner($params = [])
    {
        if (self::$bannerRendered || !(int) Configuration::get(self::CONFIG_SHOW_BANNER)) {
            return '';
        }

        if (!$this->isArchivedProductFromParams($params)) {
            return '';
        }

        self::$bannerRendered = true;

        $this->context->smarty->assign([
            'archived_message' => $this->getArchivedMessage(),
        ]);

        return $this->display(__FILE__, 'archived-banner.tpl');
    }

    private function getArchivedMessage()
    {
        $idLang = (int) $this->context->language->id;
        $message = Configuration::get(self::CONFIG_CUSTOM_MESSAGE, $idLang);

        if (!empty($message)) {
            return (string) $message;
        }

        return $this->l(
            'This product has been withdrawn from sale. The page is kept online for reference and SEO purposes.'
        );
    }

    private function getDefaultMessageForIso($isoCode)
    {
        $isoCode = strtolower(substr($isoCode, 0, 2));

        $messages = [
            'pl' => 'Ten produkt został wycofany ze sprzedaży. Strona pozostaje dostępna w celach informacyjnych i SEO.',
            'fr' => 'Ce produit a été retiré de la vente. La page reste accessible à des fins d\'information et de référencement.',
            'de' => 'Dieses Produkt wurde aus dem Verkauf genommen. Die Seite bleibt zu Informations- und SEO-Zwecken verfügbar.',
            'es' => 'Este producto ha sido retirado de la venta. La página permanece disponible con fines informativos y SEO.',
            'it' => 'Questo prodotto è stato ritirato dalla vendita. La pagina rimane disponibile per scopi informativi e SEO.',
            'pt' => 'Este produto foi retirado da venda. A página permanece disponível para fins informativos e SEO.',
            'nl' => 'Dit product is uit de verkoop gehaald. De pagina blijft beschikbaar voor informatieve en SEO-doeleinden.',
        ];

        return $messages[$isoCode] ?? 'This product has been withdrawn from sale. The page is kept online for reference and SEO purposes.';
    }
}
