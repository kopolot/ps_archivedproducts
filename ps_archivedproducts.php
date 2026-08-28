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

use PrestaShop\PrestaShop\Core\Domain\Product\ValueObject\RedirectType;

class Ps_Archivedproducts extends Module
{
    /** @var bool Prevents recursive updates from product hooks */
    private static $isUpdatingRedirect = false;

    public const CONFIG_AUTO_REDIRECT = 'PS_ARCHIVEDPRODUCTS_AUTO_REDIRECT';
    public const CONFIG_SHOW_BANNER = 'PS_ARCHIVEDPRODUCTS_SHOW_BANNER';
    public const CONFIG_HIDE_PRICE = 'PS_ARCHIVEDPRODUCTS_HIDE_PRICE';
    public const CONFIG_CUSTOM_MESSAGE = 'PS_ARCHIVEDPRODUCTS_CUSTOM_MESSAGE';

    public function __construct()
    {
        $this->name = 'ps_archivedproducts';
        $this->tab = 'seo';
        $this->version = '1.0.0';
        $this->author = 'kopolot';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Archived Products');
        $this->description = $this->l(
            'Keeps inactive product pages online (HTTP 200) for SEO while blocking orders. Automatically sets the correct redirect type when products are deactivated.'
        );
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionProductSave')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('filterProductContent')
            && $this->installConfiguration();
    }

    public function uninstall()
    {
        return $this->uninstallConfiguration()
            && parent::uninstall();
    }

    private function installConfiguration(): bool
    {
        $languages = Language::getLanguages(false);
        $defaultMessages = [];

        foreach ($languages as $language) {
            $defaultMessages[(int) $language['id_lang']] = $this->getDefaultMessageForIso($language['iso_code']);
        }

        return Configuration::updateValue(self::CONFIG_AUTO_REDIRECT, 1)
            && Configuration::updateValue(self::CONFIG_SHOW_BANNER, 1)
            && Configuration::updateValue(self::CONFIG_HIDE_PRICE, 0)
            && Configuration::updateValue(self::CONFIG_CUSTOM_MESSAGE, $defaultMessages, true)
            && Configuration::updateValue('PS_PRODUCT_REDIRECTION_DEFAULT', RedirectType::TYPE_SUCCESS_DISPLAYED);
    }

    private function uninstallConfiguration(): bool
    {
        return Configuration::deleteByName(self::CONFIG_AUTO_REDIRECT)
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

    private function processConfigurationForm(): string
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

        Configuration::updateValue(self::CONFIG_AUTO_REDIRECT, (int) Tools::getValue(self::CONFIG_AUTO_REDIRECT));
        Configuration::updateValue(self::CONFIG_SHOW_BANNER, (int) Tools::getValue(self::CONFIG_SHOW_BANNER));
        Configuration::updateValue(self::CONFIG_HIDE_PRICE, (int) Tools::getValue(self::CONFIG_HIDE_PRICE));
        Configuration::updateValue(self::CONFIG_CUSTOM_MESSAGE, $messages, true);

        return $this->displayConfirmation($this->l('Settings updated.'));
    }

    private function migrateExistingInactiveProducts(): string
    {
        $updated = $this->applyArchivedRedirectToInactiveProducts();

        return $this->displayConfirmation(
            sprintf($this->l('%d inactive products updated with archived redirect.'), $updated)
        );
    }

    private function renderConfigurationForm(): string
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
                        'label' => $this->l('Auto-set redirect on deactivation'),
                        'name' => self::CONFIG_AUTO_REDIRECT,
                        'desc' => $this->l(
                            'When a product is deactivated, automatically set its redirect type to "Displayed product page (200)" so the URL stays accessible.'
                        ),
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'auto_redirect_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'auto_redirect_off', 'value' => 0, 'label' => $this->l('No')],
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

        $migrateForm = '
            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-refresh"></i> ' . $this->l('Migrate existing products') . '
                </div>
                <div class="panel-body">
                    <p>' . $this->l(
                        'Apply the archived redirect (HTTP 200, page displayed) to all currently inactive products that still use a 404 redirect.'
                    ) . '</p>
                    <form method="post" action="' . $helper->currentIndex . '&token=' . $helper->token . '">
                        <button type="submit" name="submitPsArchivedProductsMigrate" class="btn btn-primary">
                            <i class="icon-refresh"></i> ' . $this->l('Update inactive products') . '
                        </button>
                    </form>
                </div>
            </div>';

        return $helper->generateForm([$fieldsForm]) . $migrateForm;
    }

    private function getConfigurationFormValues(array $languages): array
    {
        $values = [
            self::CONFIG_AUTO_REDIRECT => (int) Configuration::get(self::CONFIG_AUTO_REDIRECT),
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

    public function hookActionProductSave(array $params): void
    {
        $this->handleProductArchiveState($params);
    }

    public function hookActionProductUpdate(array $params): void
    {
        $this->handleProductArchiveState($params);
    }

    private function handleProductArchiveState(array $params): void
    {
        if (self::$isUpdatingRedirect || !(int) Configuration::get(self::CONFIG_AUTO_REDIRECT)) {
            return;
        }

        if (empty($params['product']) || !($params['product'] instanceof Product)) {
            return;
        }

        /** @var Product $product */
        $product = $params['product'];

        if (!(int) $product->id) {
            return;
        }

        if (!(int) $product->active) {
            $this->applyArchivedRedirect($product);

            return;
        }

        if ($this->isArchivedRedirectType((string) $product->redirect_type)) {
            $this->updateProductRedirectType((int) $product->id, RedirectType::TYPE_DEFAULT);
        }
    }

    public function hookDisplayHeader(): void
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

    public function hookDisplayProductAdditionalInfo(array $params): string
    {
        if (!(int) Configuration::get(self::CONFIG_SHOW_BANNER)) {
            return '';
        }

        if (!$this->isArchivedProductFromParams($params)) {
            return '';
        }

        $this->context->smarty->assign([
            'archived_message' => $this->getArchivedMessage(),
        ]);

        return $this->fetch('module:ps_archivedproducts/views/templates/hook/archived-banner.tpl');
    }

    public function hookFilterProductContent(array $params): array
    {
        if (empty($params['object']) || !is_array($params['object'])) {
            return $params;
        }

        $product = $params['object'];

        if (!$this->isArchivedProductData($product)) {
            return $params;
        }

        $product['is_archived'] = true;
        $product['archived_message'] = $this->getArchivedMessage();

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

        $params['object'] = $product;

        return $params;
    }

    private function applyArchivedRedirect(Product $product): void
    {
        if ($this->isArchivedRedirectType((string) $product->redirect_type)) {
            return;
        }

        if ($this->isExplicitRedirectType((string) $product->redirect_type)) {
            return;
        }

        $this->updateProductRedirectType((int) $product->id, RedirectType::TYPE_SUCCESS_DISPLAYED);
    }

    private function updateProductRedirectType(int $idProduct, string $redirectType): void
    {
        self::$isUpdatingRedirect = true;

        Db::getInstance()->update(
            'product',
            ['redirect_type' => pSQL($redirectType)],
            'id_product = ' . $idProduct
        );

        Db::getInstance()->update(
            'product_shop',
            ['redirect_type' => pSQL($redirectType)],
            'id_product = ' . $idProduct
        );

        self::$isUpdatingRedirect = false;
    }

    private function applyArchivedRedirectToInactiveProducts(): int
    {
        $shopId = (int) $this->context->shop->id;
        $rows = Db::getInstance()->executeS(
            'SELECT p.id_product
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.id_product = p.id_product AND ps.id_shop = ' . $shopId . '
            WHERE ps.active = 0
                AND (
                    ps.redirect_type = ""
                    OR ps.redirect_type = "' . pSQL(RedirectType::TYPE_DEFAULT) . '"
                    OR ps.redirect_type = "' . pSQL(RedirectType::TYPE_NOT_FOUND) . '"
                    OR ps.redirect_type = "' . pSQL(RedirectType::TYPE_NOT_FOUND_DISPLAYED) . '"
                )'
        );

        if (!is_array($rows)) {
            return 0;
        }

        $updated = 0;

        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $this->updateProductRedirectType($idProduct, RedirectType::TYPE_SUCCESS_DISPLAYED);
            ++$updated;
        }

        return $updated;
    }

    private function isArchivedRedirectType(string $redirectType): bool
    {
        return in_array($redirectType, [
            RedirectType::TYPE_SUCCESS_DISPLAYED,
            RedirectType::TYPE_NOT_FOUND_DISPLAYED,
            RedirectType::TYPE_GONE_DISPLAYED,
        ], true);
    }

    private function isExplicitRedirectType(string $redirectType): bool
    {
        return in_array($redirectType, [
            RedirectType::TYPE_PRODUCT_PERMANENT,
            RedirectType::TYPE_PRODUCT_TEMPORARY,
            RedirectType::TYPE_CATEGORY_PERMANENT,
            RedirectType::TYPE_CATEGORY_TEMPORARY,
            RedirectType::TYPE_GONE,
        ], true);
    }

    private function isProductControllerWithArchivedProduct(): bool
    {
        if (!($this->context->controller instanceof ProductController)) {
            return false;
        }

        $product = $this->context->controller->getProduct();

        return $product instanceof Product && !(int) $product->active;
    }

    private function isArchivedProductFromParams(array $params): bool
    {
        if (!empty($params['product'])) {
            return $this->isArchivedProductData($params['product']);
        }

        return $this->isProductControllerWithArchivedProduct();
    }

    private function isArchivedProductData($product): bool
    {
        if (is_array($product)) {
            return isset($product['active']) && !(int) $product['active'];
        }

        if (is_object($product) && isset($product->active)) {
            return !(int) $product->active;
        }

        return false;
    }

    private function getArchivedMessage(): string
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

    private function getDefaultMessageForIso(string $isoCode): string
    {
        if ($isoCode === 'pl') {
            return 'Ten produkt został wycofany ze sprzedaży. Strona pozostaje dostępna w celach informacyjnych i SEO.';
        }

        return 'This product has been withdrawn from sale. The page is kept online for reference and SEO purposes.';
    }
}
