<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

use PrestaShop\Module\PrestashopCheckout\Exception\PsCheckoutException;

/**
 * This controller receive ajax call to create a PayPal Order
 */
class Ps_CheckoutCreateModuleFrontController extends ModuleFrontController
{
    /**
     * @var Ps_checkout
     */
    public $module;

    /**
     * @see FrontController::postProcess()
     *
     * @todo Move logic to a Service
     */
    public function postProcess()
    {
        header('content-type:application/json');

        try {
            // BEGIN Express Checkout
            $bodyValues = [];
            $bodyContent = file_get_contents('php://input');

            if (false === empty($bodyContent)) {
                $bodyValues = json_decode($bodyContent, true);
            }

            if (isset($bodyValues['quantity_wanted'], $bodyValues['id_product'], $bodyValues['id_product_attribute'], $bodyValues['id_customization'])) {
                $cart = new Cart();
                $cart->id_currency = $this->context->currency->id;
                $cart->id_lang = $this->context->language->id;
                $cart->add();
                $cart->updateQty(
                    (int) $bodyValues['quantity_wanted'],
                    (int) $bodyValues['id_product'],
                    empty($bodyValues['id_product_attribute']) ? null : (int) $bodyValues['id_product_attribute'],
                    empty($bodyValues['id_customization']) ? false : (int) $bodyValues['id_customization'],
                    $operator = 'up'
                );
                $cart->update();

                $this->module->getLogger()->info(sprintf(
                    'Express checkout : Create Cart %s',
                    (int) $cart->id
                ));

                $this->context->cart = $cart;
                $this->context->cookie->__set('id_cart', (int) $cart->id);
            }
            // END Express Checkout

            if (false === Validate::isLoadedObject($this->context->cart)) {
                throw new PsCheckoutException('No cart found.', PsCheckoutException::PRESTASHOP_CONTEXT_INVALID);
            }

            $psCheckoutCartCollection = new PrestaShopCollection('PsCheckoutCart');
            $psCheckoutCartCollection->where('id_cart', '=', (int) $this->context->cart->id);

            /** @var PsCheckoutCart|false $psCheckoutCart */
            $psCheckoutCart = $psCheckoutCartCollection->getFirst();

            if (false !== $psCheckoutCart && false === empty($psCheckoutCart->paypal_order)) {
                // @todo Check if PayPal Order status before reuse it
                header('content-type:application/json');
                echo json_encode([
                    'status' => true,
                    'httpCode' => 200,
                    'body' => [
                        'orderID' => $psCheckoutCart->paypal_order,
                    ],
                    'exceptionCode' => null,
                    'exceptionMessage' => null,
                ]);
                exit;
            }

            $paypalOrder = new PrestaShop\Module\PrestashopCheckout\Handler\CreatePaypalOrderHandler($this->context);
            $response = $paypalOrder->handle();

            if (false === $response['status']) {
                throw new PsCheckoutException($response['exceptionMessage'], (int) $response['exceptionCode']);
            }

            if (empty($response['body']['id'])) {
                throw new PsCheckoutException('Paypal order id is missing.', PsCheckoutException::PAYPAL_ORDER_IDENTIFIER_MISSING);
            }

            if (false === $psCheckoutCart) {
                $psCheckoutCart = new PsCheckoutCart();
                $psCheckoutCart->id_cart = (int) $this->context->cart->id;
            }

            $psCheckoutCart->paypal_order = $response['body']['id'];
            $psCheckoutCart->paypal_status = $response['body']['status'];
            $psCheckoutCart->paypal_intent = 'CAPTURE' === Configuration::get('PS_CHECKOUT_INTENT') ? 'CAPTURE' : 'AUTHORIZE';
            $psCheckoutCart->paypal_token = $response['body']['client_token'];
            $psCheckoutCart->paypal_token_expire = (new DateTime())->modify('+3550 seconds')->format('Y-m-d H:i:s');
            $psCheckoutCart->save();

            echo json_encode([
                'status' => true,
                'httpCode' => 200,
                'body' => [
                    'orderID' => $response['body']['id'],
                ],
                'exceptionCode' => null,
                'exceptionMessage' => null,
            ]);
        } catch (Exception $exception) {
            header('HTTP/1.0 400 Bad Request');

            echo json_encode([
                'status' => false,
                'httpCode' => 400,
                'body' => '',
                'exceptionCode' => $exception->getCode(),
                'exceptionMessage' => $exception->getMessage(),
            ]);
        }

        exit;
    }
}