<?php

declare(strict_types=1);

namespace AvS\ScopeHint\Plugin;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure\Element\Field as Subject;
use Magento\Config\Model\Config\TypePool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManager\ConfigInterface as DiConfig;
use Magento\Framework\Phrase;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;

class ConfigFieldPlugin
{
    const SCOPE_TYPE_WEBSITES = 'websites';
    const SCOPE_TYPE_STORES = 'stores';

    private const CONFIG_SHOW_PATH = 'dev/debug/show_path_in_adminhtml';

    /** @var Escaper */
    private $escaper;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;
    /**
     * @var WebsiteRepositoryInterface
     */
    private WebsiteRepositoryInterface $websiteRepository;
    /**
     * @var StoreRepositoryInterface
     */
    private StoreRepositoryInterface $storeRepository;
    /**
     * @var RequestInterface
     */
    private RequestInterface $request;
    /**
     * @var TypePool
     */
    private TypePool $typePool;
    /**
     * @var DiConfig
     */
    private DiConfig $diConfig;

     /**
     * @var bool
      */
    private bool $isProcessing = false;

    public function __construct(
        Escaper $escaper,
        ScopeConfigInterface $scopeConfig,
        WebsiteRepositoryInterface $websiteRepository,
        StoreRepositoryInterface $storeRepository,
        RequestInterface $request,
        TypePool $typePool,
        DiConfig $diConfig
    ) {
        $this->escaper = $escaper;
        $this->scopeConfig = $scopeConfig;
        $this->websiteRepository = $websiteRepository;
        $this->storeRepository = $storeRepository;
        $this->request = $request;
        $this->typePool = $typePool;
        $this->diConfig = $diConfig;
    }

    /**
     * @param Subject $subject
     * @param string $result
     * @return string
     */
    public function afterGetTooltip(Subject $subject, $result)
    {
        $lines = [$result];
        $isSensitive = $this->isSensitiveField($subject);
        foreach($this->websiteRepository->getList() as $website) {
            if ($this->getWebsiteParam() || $this->getStoreParam()) {
                continue;
            }
            // Only show website specific values in default scope
            if ($scopeLine = $this->getScopeHint($subject, self::SCOPE_TYPE_WEBSITES, $website, $isSensitive)) {
                $lines[] = $scopeLine;
            }
        }
        foreach($this->storeRepository->getList() as $store) {
            if ($this->getStoreParam($store)) {
                continue;
            }
            if (($websiteId = $this->getWebsiteParam()) && ($store->getWebsiteId() != $websiteId)) {
                continue;
            }
            // Only show store specific values in default scope and in parent website scope
            if ($scopeLine = $this->getScopeHint($subject, self::SCOPE_TYPE_STORES, $store, $isSensitive)) {
                $lines[] = $scopeLine;
            }
        }
        return implode('<br />', array_filter($lines));
    }

    /**
     * @param Subject $subject
     * @param array $result
     * @return array
     */
    public function afterGetData(
        Subject $subject,
        array $result
    ) {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_SHOW_PATH)) {
            return $result;
        }

        // to avoid a potential infinite loop, we keep a flag to see if we are already handling this particular field
        if (!$this->isProcessing) {
            $this->isProcessing = true;

             $result['path_hint'] = '<small>'
                 . __('Path: <code>%1</code>', $this->escaper->escapeHtml($this->getPath($subject)))
                 . '</small>';

            $this->isProcessing = false;
         }

        return $result;
    }

    /**
     * @param Subject $subject
     * @return string
     */
    private function getPath(Subject $subject)
    {
        return $path = $subject->getConfigPath() ?: $subject->getPath();
    }

    /**
     * @param Subject $field
     * @return bool
     */
    private function isSensitiveField(Subject $field): bool
    {
        if (in_array($field->getType(), ['obscure', 'password'], true)
            || $this->typePool->isPresent($this->getPath($field), TypePool::TYPE_SENSITIVE)
        ) {
            return true;
        }
        $backendModel = $field->getAttribute('backend_model');
        if (!is_string($backendModel) || $backendModel === '') {
            return false;
        }
        // Resolve virtual types to their real class before checking
        $backendClass = $this->diConfig->getInstanceType(ltrim($backendModel, '\\'));
        return is_a($backendClass, Encrypted::class, true);
    }

    /**
     * @param Subject $field
     * @param string $scopeType
     * @param WebsiteInterface|StoreInterface $scope
     * @param bool $isSensitive
     * @return string
     */
    private function getScopeHint(Subject $field, $scopeType, $scope, bool $isSensitive)
    {
        $path = $this->getPath($field);
        $scopeLine = '';
        if ($websiteId = $this->getWebsiteParam()) {
            $currentValue = $this->scopeConfig->getValue(
                $path,
                ScopeInterface::SCOPE_WEBSITE,
                $websiteId
            );
        } else {
            $currentValue = $this->scopeConfig->getValue($path);
        }
        $scopeValue = $this->scopeConfig->getValue($path, $scopeType, $scope->getId());

        if (is_array($currentValue) || is_array($scopeValue)) {
            return $scopeLine;
        }

        $currentValue = (string) $currentValue;
        $scopeValue = (string) $scopeValue;

        if ($scopeValue != $currentValue) {
            // Match options on the raw value, escape only once for output
            $scopeCode = $this->escaper->escapeHtml((string) $scope->getCode());

            // Never reveal values of secret fields (passwords, API keys etc.), only that they differ
            if ($isSensitive) {
                return $scopeType === self::SCOPE_TYPE_STORES
                    ? __('Store <code>%1</code>: value differs', $scopeCode)
                    : __('Website <code>%1</code>: value differs', $scopeCode);
            }

            $valueLabel = $this->escaper->escapeHtml((string) $this->getValueLabel($field, $scopeValue));

            switch($scopeType) {
                case self::SCOPE_TYPE_STORES:
                    return __(
                        'Store <code>%1</code>: "%2"',
                        $scopeCode,
                        $valueLabel
                    );
                case self::SCOPE_TYPE_WEBSITES:
                    return __(
                        'Website <code>%1</code>: "%2"',
                        $scopeCode,
                        $valueLabel
                    );
            }
        }
        return $scopeLine;
    }

    private function getValueLabel(Subject $field, string $scopeValue)
    {
        $scopeValue = trim($scopeValue);
        if ($field->hasOptions()) {
            if ($field->getType() === 'multiselect' && strpos($scopeValue, ',') !== false) {
                return implode(
                    ', ',
                    array_map(
                        function ($key) use ($field) {
                            return $this->getValueLabel($field, $key);
                        },
                        explode(',', $scopeValue)
                    )
                );
            }
            foreach ($field->getOptions() as $option) {
                if (is_array($option) && $option['value'] == $scopeValue) {
                    return $option['label'];
                }
            }
        }
        return $scopeValue;
    }

    private function getWebsiteParam(): ?string
    {
        return $this->request->getParam('website');
    }

    private function getStoreParam(): ?string
    {
        return $this->request->getParam('store');
    }
}
