<?php

class Lumidatum_ProdRecWidget_Block_Recs extends Mage_Core_Block_Abstract implements Mage_Widget_Block_Interface
{
    protected function _toHtml()
    {
        Mage::log('Starting Lumidatum Product Recommender widget processing', null, 'lumidatum-productrecommender.log');
        $html = '<ol class="products-grid">';
        $includedProductIds = array();
        $currentStore = Mage::app()->getStore();

        $lumidatumProdRecImageSize = Mage::getStoreConfig(
            'lumidatum/prodrecwidget_group/lumidatum_prodrecimagesize',
            $currentStore
        );

        // Get product Id if on a product detail page
        $currentProduct = Mage::registry('current_product');
        if($currentProduct)
        {
            $productId = $currentProduct->getId();
            Mage::log("Product view, product Id: {$productId}", null, 'lumidatum-productrecommender.log');

            // Add current product Id to included product Ids (as int, not string)
            array_push($includedProductIds, (int) $productId);
        }

        // Get user Id, if user is logged in
        $customerId = Mage::getSingleton('customer/session')->getCustomer()->getId();
        if($customerId)
        {
            Mage::log("Customer Id: {$customerId}", null, 'lumidatum-productrecommender.log');

            // Get customer history and add product Ids to included product Ids
            $orders= Mage::getModel('sales/order')->getCollection()->addAttributeToFilter('customer_id', $customerId);
            foreach($orders as $collectionOrder)
            {
                $order = Mage::getModel('sales/order')->load($collectionOrder->getId());
                $items = $order->getAllVisibleItems();
                foreach($items as $item)
                {
                    array_push($includedProductIds, (int) $item->getProductId());
                }
            }
        }

        // If included product ids is not empty, make an API call to Lumidatum
        if($includedProductIds)
        {
            $recommendationProductArrays = $this->getProductRecommendations($currentStore, $customerId, $includedProductIds);
            $recommendationProductIds = array_map(function($recommendationProductArray) { return $recommendationProductArray[0]; }, $recommendationProductArrays);

            $recommendedProducts = Mage::getModel('catalog/product')
                ->getCollection()
                ->addAttributeToSelect('name')
                ->addAttributeToSelect('price')
                ->addAttributeToSelect('small_image')
                ->addAttributeToFilter('entity_id', $recommendationProductIds)
                ->load();

            $i = 0;

            foreach($recommendedProducts as $recommendedProduct)
            {
                // TODO: figure out how to use a template instead
                $itemLast = (++$i == count($recommendedProducts))?' last':'';
                $productId = $recommendedProduct->getId();
                $productUrl = $recommendedProduct->getProductUrl();
                $productName = $recommendedProduct->getName();
                $productTitle = $this->stripTags($recommendedProduct->getName(), null, true);
                $productImgSrc = Mage::helper('catalog/image')->init($recommendedProduct, 'small_image')->resize($lumidatumProdRecImageSize);
                $productImgSrcAlt = $this->stripTags($recommendedProduct->getName(), null, true);
                $productPrice = number_format($recommendedProduct->getPrice(), 2);

                $html .= "<li class=\"item{$itemLast}\">";

                // Product image with link
                $html .= "<a href=\"{$productUrl}\" title=\"{$productTitle}\" class=\"product-image\" style=\"width:{$lumidatumProdRecImageSize}px; height:{$lumidatumProdRecImageSize}px;\"><img src=\"{$productImgSrc}\" width=\"{$lumidatumProdRecImageSize}px\" height=\"{$lumidatumProdRecImageSize}px\" alt=\"{$productImgSrcAlt}\" /></a>";

                // Product name with link
                $html .= "<h3 class=\"product-name\"><a href=\"{$productUrl}\" title=\"{$productTitle}\">{$productName}</a></h3>";

                // Price
                $html .= "<div class=\"price-box\"><span class=\"regular-price\" id=\"product-price-{$productId}\"><span class=\"price\">\${$productPrice}</span></span></div>";

                $html .= "</li>";
            }
        }
        else
        {
            Mage::log("Included product Ids empty", null, 'lumidatum-productrecommender.log');
        }

        $html .= '</ol>';

        return $html;
    }

    public function getProductRecommendations($currentStore, $customerId, array $includedProductIds)
    {
        if(!$customerId)
        {
            $customerId = 'User not logged in';
        }
        $includedProductIdsString = implode($includedProductIds, ',');
        Mage::log(
            "Product Ids: {$includedProductIdsString}, Customer Id: {$customerId}",
            null,
            'lumidatum-productrecommender.log'
        );

        $lumidatumRestApiBaseUrl = 'https://www.lumidatum.com/api/predict';

        // Get from API Credentials from configuration set in Admin dashboard
        $lumidatumModelId = Mage::getStoreConfig(
            'lumidatum/prodrecwidget_group/lumidatum_modelid',
            $currentStore
        );
        $lumidatumApiKey = Mage::getStoreConfig(
            'lumidatum/prodrecwidget_group/lumidatum_apikey',
            $currentStore
        );
        $lumidatumProdRecCount = Mage::getStoreConfig(
            'lumidatum/prodrecwidget_group/lumidatum_prodreccount',
            $currentStore
        );

        Mage::log(
            "Model Id: {$lumidatumModelId}, Authorization: {$lumidatumApiKey}, Url: {$lumidatumRestApiBaseUrl}/{$lumidatumModelId}",
            null,
            'lumidatum-productrecommender.log'
        );

        if($lumidatumModelId and $lumidatumApiKey)
        {
            $client = new Varien_Http_Client("{$lumidatumRestApiBaseUrl}/{$lumidatumModelId}");
            $client->setMethod(Varien_Http_Client::POST);
            $client->setHeaders('Authorization', $lumidatumApiKey);
            $client->setHeaders('Content-Type', 'application/json');

            $data = array(
                'customer_id' => $customerId,
                'inc_prod_ids' => $includedProductIds,
                'exc_prod_ids' => $includedProductIds,
                'num_of_recs' => (int) $lumidatumProdRecCount,
            );
            $json = json_encode($data);

            Mage::log("JSON payload: {$json}", null, 'lumidatum-productrecommender.log');

            // TODO: add guard/http external failure handling... to not take down magento site on call failure...
            $response = $client->setRawData($json)->request('POST');
            if ($response->isSuccessful())
            {
                $responseBody = $response->getBody();
                $recommendations = json_decode($responseBody)->recommendations;

                Mage::log("Response body: {$responseBody}", null, 'lumidatum-productrecommender.log');

                // TODO: decide whether or noto dispatch an event after receiving recommendations
                // Mage::dispatchEvent('lumidatum_product_recommendation', array('recommendations' => $recommendations));
                return $recommendations;
            }
            else
            {
                Mage::log(
                    'Response error: ' . $response->getStatus() . ' ' . $response->getMessage(),
                    null,
                    'lumidatum-productrecommender.log'
                );
            }
        }
        else
        {
            Mage::log('API call not made: missing model Id or API token', null, 'lumidatum-productrecommender.log');
        }

        return array();
    }
}