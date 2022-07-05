<?php

declare(strict_types=1);

namespace Easproject\Eucompliance\Block\Checkout;

use Easproject\Eucompliance\Model\Config\Configuration;
use Magento\Checkout\Block\Checkout\AttributeMerger;
use Magento\Customer\Model\AttributeMetadataDataProvider;
use Magento\Customer\Model\Options;
use Magento\Ui\Component\Form\AttributeMapper;

class LayoutProcessor
{
    /**
     * @var AttributeMetadataDataProvider
     */
    private $attributeMetadataDataProvider;

    /**
     * @var AttributeMapper
     */
    protected $attributeMapper;

    /**
     * @var AttributeMerger
     */
    protected $merger;

    /**
     * @var Options
     */
    private Options $options;

    /**
     * @var Configuration
     */
    private Configuration $configuration;

    /**
     * @param AttributeMetadataDataProvider $attributeMetadataDataProvider
     * @param AttributeMapper               $attributeMapper
     * @param AttributeMerger               $merger
     * @param Options                       $options
     * @param Configuration                 $configuration
     */
    public function __construct(
        AttributeMetadataDataProvider $attributeMetadataDataProvider,
        AttributeMapper               $attributeMapper,
        AttributeMerger               $merger,
        Options                       $options,
        Configuration                 $configuration
    ) {
        $this->options = $options;
        $this->configuration = $configuration;
        $this->attributeMetadataDataProvider = $attributeMetadataDataProvider;
        $this->attributeMapper = $attributeMapper;
        $this->merger = $merger;
    }

    /**
     * @return Options
     */
    private function getOptions()
    {
        return $this->options;
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getAddressAttributes()
    {
        /**
         * @var \Magento\Eav\Api\Data\AttributeInterface[] $attributes
         */
        $attributes = $this->attributeMetadataDataProvider->loadAttributesCollection(
            'customer_address',
            'customer_register_address'
        );

        $elements = [];
        foreach ($attributes as $attribute) {
            $code = $attribute->getAttributeCode();
            if ($attribute->getIsUserDefined()) {
                continue;
            }
            $elements[$code] = $this->attributeMapper->map($attribute);
            if (isset($elements[$code]['label'])) {
                $label = $elements[$code]['label'];
                $elements[$code]['label'] = __($label);
            }
        }
        return $elements;
    }

    /**
     * Convert elements(like prefix and suffix) from inputs to selects when necessary
     *
     * @param  array $elements            address attributes
     * @param  array $attributesToConvert fields and their callbacks
     * @return array
     */
    private function convertElementsToSelect($elements, $attributesToConvert)
    {
        $codes = array_keys($attributesToConvert);
        foreach (array_keys($elements) as $code) {
            if (!in_array($code, $codes)) {
                continue;
            }
            $method = $attributesToConvert[$code]['method'];
            $options = $attributesToConvert[$code]['class']->$method();
            if (!is_array($options)) {
                continue;
            }
            $elements[$code]['dataType'] = 'select';
            $elements[$code]['formElement'] = 'select';

            foreach ($options as $key => $value) {
                $elements[$code]['options'][] = [
                    'value' => $key,
                    'label' => $value,
                ];
            }
        }

        return $elements;
    }

    /**
     * Process js Layout of block
     *
     * @param $jsLayout
     *
     * @return array|mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function process($jsLayout)
    {
        if (!$this->configuration->isEnabled()) {
            return $jsLayout;
        }

        //Rename tax
        $jsLayout['components']['checkout']['children']['sidebar']['children']
        ['summary']['children']['totals']['children']['tax']['config']['title'] =
            __($this->configuration->getTaxLabel());

        $attributesToConvert = [
            'prefix' => ['class' => $this->getOptions(), 'method' => 'getNamePrefixOptions'],
            'suffix' => ['class' => $this->getOptions(), 'method' => 'getNameSuffixOptions'],
        ];

        $elements = $this->getAddressAttributes();
        $elements = $this->convertElementsToSelect($elements, $attributesToConvert);
        if (isset($jsLayout['components']['checkout']['children']['steps']['children']
            ['eas-billing-step']['children']['eas-billing']['children'])
        ) {
            $jsLayout['components']['checkout']['children']['steps']
            ['children']['eas-billing-step']['children']['customer-email'] =
                [
                    'component' => 'Magento_Checkout/js/view/form/element/email',
                    'displayArea' => 'customer-email',
                    'tooltip' => [
                        'description' => "We'll send your order confirmation here."
                    ],
                    'children' => [
                        'before-login-form' => [
                            'component' => 'uiComponent',
                            'displayArea' => 'before-login-form',
                        ],
                        'additional-login-form-fields' => [
                            'component' => 'uiComponent',
                            'displayArea' => 'additional-login-form-fields'
                        ]
                    ]
                ];

            $jsLayout['components']['checkout']['children']['steps']['children']['eas-billing-step']['children']
            ['eas-billing']['children'] = $this->processNewStepsChildrenComponents(
                $jsLayout['components']['checkout']['children']['steps']['children']['billing-step']['children']
                ['payment']['children'],
                $jsLayout['components']['checkout']['children']['steps']['children']['eas-billing-step']['children']
                ['eas-billing']['children'],
                $elements
            );

        }

        $jsLayout['components']['checkout']['children']['steps']['children']['eas-billing-step']['children'] =
            array_reverse(
                $jsLayout['components']['checkout']['children']['steps']['children']['eas-billing-step']['children']
            );

        return $jsLayout;
    }

    /**
     * Appends billing address form component to payment layout
     *
     * @param array $paymentLayout
     * @param array $elements
     *
     * @return array
     */
    private function processNewStepsChildrenComponents(
        array $paymentLayout,
        array $newStepsLayout,
        array $elements
    ) {
        $component = [];
        if (!isset($newStepsLayout['afterMethods']['children'])) {
            $newStepsLayout['afterMethods']['children'] = [];
        }

        $component['billing-address-form'] = $this->getBillingAddressComponent(
            'shared',
            $elements
        );

        $newStepsLayout['afterMethods']['children'] = array_merge_recursive(
            $component,
            $newStepsLayout['afterMethods']['children']
        );

        return $newStepsLayout;
    }

    /**
     * Gets billing address component details
     *
     * @param string $paymentCode
     * @param array  $elements
     *
     * @return array
     */
    private function getBillingAddressComponent($paymentCode, $elements)
    {
        return [
            'component' => 'Magento_Checkout/js/view/billing-address',
            'displayArea' => 'billing-address-form-' . $paymentCode,
            'provider' => 'checkoutProvider',
            'deps' => 'checkoutProvider',
            'dataScopePrefix' => 'billingAddress' . $paymentCode,
            'sortOrder' => 2,
            'children' => [
                'form-fields' => [
                    'component' => 'uiComponent',
                    'displayArea' => 'additional-fieldsets',
                    'children' => $this->merger->merge(
                        $elements,
                        'checkoutProvider',
                        'billingAddress' . $paymentCode,
                        [
                            'country_id' => [
                                'sortOrder' => 115,
                            ],
                            'region' => [
                                'visible' => false,
                            ],
                            'region_id' => [
                                'component' => 'Magento_Ui/js/form/element/region',
                                'config' => [
                                    'template' => 'ui/form/field',
                                    'elementTmpl' => 'ui/form/element/select',
                                    'customEntry' => 'billingAddress' . $paymentCode . '.region',
                                ],
                                'validation' => [
                                    'required-entry' => true,
                                ],
                                'filterBy' => [
                                    'target' => '${ $.provider }:${ $.parentScope }.country_id',
                                    'field' => 'country_id',
                                ],
                            ],
                            'postcode' => [
                                'component' => 'Magento_Ui/js/form/element/post-code',
                                'validation' => [
                                    'required-entry' => true,
                                ],
                            ],
                            'company' => [
                                'validation' => [
                                    'min_text_length' => 0,
                                ],
                            ],
                            'fax' => [
                                'validation' => [
                                    'min_text_length' => 0,
                                ],
                            ],
                            'telephone' => [
                                'config' => [
                                    'tooltip' => [
                                        'description' => __('For delivery questions.'),
                                    ],
                                ],
                            ],
                        ]
                    ),
                ],
            ],
        ];
    }
}
