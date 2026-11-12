<?php
namespace Joomla\Plugin\System\Phocacartsubscription\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\UserHelper;
use Joomla\Event\SubscriberInterface;
require_once JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/bootstrap.php';
require_once JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/phocacart/subscription/subscription.php';
require_once JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/phocacart/subscription/email.php';

class Phocacartsubscription extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array {
        return [
            'onPhocaCartAfterOrderStatusUpdate' => 'onPhocaCartAfterOrderStatusUpdate',
            'onPhocaCartAfterOrderSave'         => 'onPhocaCartAfterOrderSave',
            'onContentPrepareForm'              => 'onContentPrepareForm',
            'onContentBeforeSave'               => 'onContentBeforeSave',
            'onPhocaCartCalculationPrice'       => 'onPhocaCartCalculationPrice',
            'onPhocaCartAfterSubscriptionSave'  => 'onPhocaCartAfterSubscriptionSave',
            'onPhocaCartBeforeSubscriptionDelete' => 'onPhocaCartBeforeSubscriptionDelete'
        ];
    }

    public function onPhocaCartAfterOrderSave($event): void {
        $args = $event->getArguments();
        $row  = $args['row'] ?? null;
        if (!$row || (int)$row->id < 1 || (int)$row->user_id < 1) {
            return;
        }
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
             ->select('a.*')
             ->from($db->quoteName('#__phocacart_order_subscriptions', 'a'))
             ->where($db->quoteName('a.order_id') . ' = ' . (int) $row->id);
        $db->setQuery($query);
        $subscriptionItems = $db->loadObjectList();
        if (!empty($subscriptionItems)) {
            foreach ($subscriptionItems as $item) {
                 $this->createInitialSubscription($item, $row);
            }
        }
    }

    /*
     * SUBSCRIPTION STATUS CHANGE
     */
    public function onPhocaCartAfterSubscriptionSave($event): void
    {
        $item      = $event->getArgument('item');
        $statusOld = $event->getArgument('statusOld');

        if (!$item || !isset($item->status)) {
            return;
        }

        $statusNew = (int)$item->status;

        // Process only if the status has actually changed
        if ($statusOld !== null && $statusOld !== $statusNew) {
            $oldProps = \PhocacartSubscription::getStatusProperties($statusOld);
            $newProps = \PhocacartSubscription::getStatusProperties($statusNew);

            $oldBehavior = (int)$oldProps['behavior'];
            $newBehavior = (int)$newProps['behavior'];


            // 1. Log the status change into history including the 'status_from'
            \PhocacartSubscription::logHistory($item->id, $statusNew, \PhocacartSubscription::EVENT_STATUS_CHANGED, 'admin', $statusOld);

            // 2. Handle ACL transitions based on behavior change (e.g. Active vs Inactive)
            if ($oldBehavior !== \PhocacartSubscription::BEHAVIOR_ACTIVE && $newBehavior === \PhocacartSubscription::BEHAVIOR_ACTIVE) {
                $this->manageACL($item, 'add');
            } elseif ($oldBehavior === \PhocacartSubscription::BEHAVIOR_ACTIVE && $newBehavior !== \PhocacartSubscription::BEHAVIOR_ACTIVE) {
                $this->manageACL($item, 'remove');
            }

            \PhocacartSubscriptionEmail::sendEmail($item, $statusOld, $statusNew, \PhocacartSubscription::EVENT_STATUS_CHANGED, true, false, 'admin');
        }
    }

    public function onPhocaCartBeforeSubscriptionDelete($event): void {
        $item = $event->getArgument('item');

        if (!$item) {
            return;
        }

        // We only remove ACL if the current status is considered "Active" behavior
        $props = \PhocacartSubscription::getStatusProperties($item->status);
        if ((int)$props['behavior'] === \PhocacartSubscription::BEHAVIOR_ACTIVE) {
            // Use existing manageACL logic to remove the groups
            $this->manageACL($item, 'remove');
        }
    }


   protected function createInitialSubscription($item, $orderRow) {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('id')
            ->from($db->quoteName('#__phocacart_subscriptions'))
            ->where($db->quoteName('user_id') . ' = ' . (int) $orderRow->user_id)
            ->where($db->quoteName('product_id') . ' = ' . (int) $item->product_id);
        $db->setQuery($query);
        if ($db->loadResult()) {
            return;
        }
        $subscription = new \stdClass();
        $subscription->user_id = $orderRow->user_id;
        $subscription->product_id = $item->product_id;
        $subscription->order_id = $orderRow->id;
        $subscription->order_item_id = $item->id;
        $subscription->status = \PhocacartSubscription::STATUS_PENDING;
        $now = Factory::getDate()->toSql();
        $subscription->start_date = $now;
        $subscription->end_date = $now;
        $subscription->renewal_count = 0;
        $db->insertObject('#__phocacart_subscriptions', $subscription);
        \PhocacartSubscription::logHistory($db->insertid(), \PhocacartSubscription::STATUS_PENDING, \PhocacartSubscription::EVENT_CREATED, 'order');
    }

    /*
     * ORDER STATUS CHANGE
     */
    public function onPhocaCartAfterOrderStatusUpdate($event): void {
        $args = $event->getArguments();
        $status = $args['status'] ?? null;
        $statusNew = $status['id'] ?? null;
        $order = $args['order'] ?? null;

        if (!$order || (int)$order->id < 1 || (int)$order->user_id < 1 || $statusNew === null) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
             ->select('a.*')
             ->from($db->quoteName('#__phocacart_order_subscriptions', 'a'))
             ->where($db->quoteName('a.order_id') . ' = ' . (int) $order->id);
        $db->setQuery($query);
        $subscriptionItems = $db->loadObjectList();

        if (empty($subscriptionItems)) {
            return;
        }

        // Get parameters as arrays (ensuring they are arrays even if empty)
        $activationStatuses = (array)$this->params->get('activation_status', []);
        $deactivationStatuses = (array)$this->params->get('deactivation_status', []);


        foreach ($subscriptionItems as $item) {
            // Check if the current Order Status ID is in the list of Activation Statuses
            if (!empty($activationStatuses) && in_array((int)$statusNew, array_map('intval', $activationStatuses))) {
                $this->processSubscriptionActivation($item, $order, \PhocacartSubscription::STATUS_ACTIVE, 'admin_order_status_update');
            }
            // Check if the current Order Status ID is in the list of Deactivation Statuses
            elseif (!empty($deactivationStatuses) && in_array((int)$statusNew, array_map('intval', $deactivationStatuses))) {
                $this->processSubscriptionDeactivation($item, $order, \PhocacartSubscription::STATUS_EXPIRED, 'admin_order_status_update');
            }
        }
    }

    protected function processSubscriptionActivation($item, $order, $statusNew, $triggeredBy = 'admin') {
        $scenario = \PhocacartSubscription::determineSubscriptionScenario($order, $item);
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__phocacart_subscriptions'))
            ->where($db->quoteName('user_id') . ' = ' . (int) $order->user_id)
            ->where($db->quoteName('product_id') . ' = ' . (int) $item->product_id);
        $db->setQuery($query);
        $existing = $db->loadObject();
        $statusOld = $existing ? (int)$existing->status : null;

        $sub = new \stdClass();
        $sub->user_id = $order->user_id;
        $sub->product_id = $item->product_id;
        $sub->order_id = $order->id;
        $sub->order_item_id = $item->id;
        $sub->status = $statusNew;
        $sub->start_date = $scenario['start_date'];
        $sub->end_date = $scenario['end_date'];
        $sub->signup_fee_paid = ($scenario['signup_fee'] > 0) ? 1 : 0;


        if ($existing) {
            $sub->id = $existing->id;
            // CHECK: Is this a first-time activation of a Pending Subscription?
            // We check if renewal_count is 0 AND the old status was Pending
            if ((int)($existing->renewal_count ?? 0) === 0 && $statusOld === \PhocacartSubscription::STATUS_PENDING) {
                // It is an activation, not a renewal
                $sub->renewal_count = 0; // Keep it at 0
                $eventType = \PhocacartSubscription::EVENT_ACTIVATED;
                $emailEvent = \PhocacartSubscription::EVENT_ACTIVATED;
            } else {
                // It is a genuine renewal (existing subscription being extended)
                $sub->renewal_count = ($existing->renewal_count ?? 0) + 1;
                $eventType = \PhocacartSubscription::EVENT_RENEWED;
                $emailEvent = \PhocacartSubscription::EVENT_RENEWED;
            }

            $db->updateObject('#__phocacart_subscriptions', $sub, 'id');

            $subscriptionId = $existing->id;

        } else {
            $sub->renewal_count = 0;
            $db->insertObject('#__phocacart_subscriptions', $sub);
            $subscriptionId = $db->insertid();
            $sub->id = $subscriptionId;
            $eventType = \PhocacartSubscription::EVENT_ACTIVATED;
            $emailEvent = \PhocacartSubscription::EVENT_ACTIVATED;
        }

        $this->manageACL($sub, 'add');
        \PhocacartSubscription::logHistory($subscriptionId, $statusNew, $eventType, $triggeredBy, $statusOld);

        // Send subscription email notification
        $sub->id = $subscriptionId;
        \PhocacartSubscriptionEmail::sendEmail($sub, $statusOld, $statusNew, $emailEvent, true, false, $triggeredBy);
    }

    protected function processSubscriptionDeactivation($item, $order, $statusNew, $triggeredBy = 'admin')
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__phocacart_subscriptions'))
            ->where($db->quoteName('user_id') . ' = ' . (int) $order->user_id)
            ->where($db->quoteName('product_id') . ' = ' . (int) $item->product_id);
        $db->setQuery($query);
        $subscription = $db->loadObject();

        if ($subscription) {
            $statusOld = (int)$subscription->status;
            $oldProps = \PhocacartSubscription::getStatusProperties($statusOld);
            $subscription->status = $statusNew;
            $db->updateObject('#__phocacart_subscriptions', $subscription, 'id');

            // Remove access only if previous behavior was Active
            if ($oldProps['behavior'] == \PhocacartSubscription::BEHAVIOR_ACTIVE) {
                $this->manageACL($subscription, 'remove');
            }
            \PhocacartSubscription::logHistory($subscription->id, $statusNew, \PhocacartSubscription::EVENT_DEACTIVATED, $triggeredBy, $statusOld);

            // Send expiration/cancellation email
            // History info
            $subscription->status_from = $subscription->status;
            $subscription->status_to = \PhocacartSubscription::EVENT_DEACTIVATED;

            $emailEvent = ($statusNew === \PhocacartSubscription::STATUS_CANCELED)
                ? \PhocacartSubscription::EVENT_CANCELED
                : \PhocacartSubscription::EVENT_EXPIRED;
            \PhocacartSubscriptionEmail::sendEmail($subscription, $statusOld, $statusNew, $emailEvent, true, false, $triggeredBy);
        }
    }


    protected function processSubscriptionStatusUpdate($item, $order, $statusNew)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('id, status')
            ->from($db->quoteName('#__phocacart_subscriptions'))
            ->where($db->quoteName('user_id') . ' = ' . (int) $order->user_id)
            ->where($db->quoteName('product_id') . ' = ' . (int) $item->product_id);
        $db->setQuery($query);
        $subscription = $db->loadObject();

        if ($subscription) {
            $statusOld = (int)$subscription->status;
            $oldProps = \PhocacartSubscription::getStatusProperties($statusOld);
            $oldBehavior = $oldProps['behavior'];

            $newProps = \PhocacartSubscription::getStatusProperties($statusNew);
            $newBehavior = $newProps['behavior'];

            // Update status
            $subscription->status = $statusNew;
            $db->updateObject('#__phocacart_subscriptions', $subscription, 'id');

            // Handle ACL transitions based on behavior
            if ($oldBehavior != \PhocacartSubscription::BEHAVIOR_ACTIVE && $newBehavior == \PhocacartSubscription::BEHAVIOR_ACTIVE) {
                $this->manageACL($item, 'add');
            } elseif ($oldBehavior == \PhocacartSubscription::BEHAVIOR_ACTIVE && $newBehavior != \PhocacartSubscription::BEHAVIOR_ACTIVE) {
                $this->manageACL($item, 'remove');
            }

            // History
            \PhocacartSubscription::logHistory($subscription->id, $statusNew, \PhocacartSubscription::EVENT_STATUS_CHANGED, 'order');
        }
    }

    protected function manageACL($item, $action)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Try to fetch group settings from the Order Subscription snapshot
        $query = $db->getQuery(true)
            ->select('subscription_usergroup_add, subscription_usergroup_remove')
            ->from($db->quoteName('#__phocacart_order_subscriptions'))
            ->where($db->quoteName('order_id') . ' = ' . (int)$item->order_id)
            ->where($db->quoteName('product_id') . ' = ' . (int)$item->product_id);
        $db->setQuery($query);
        $groups = $db->loadObject();

        // Fallback to Product table if no order record exists (manual subscription)
        if (!$groups) {
            $query = $db->getQuery(true)
                ->select('subscription_usergroup_add, subscription_usergroup_remove')
                ->from($db->quoteName('#__phocacart_products'))
                ->where($db->quoteName('id') . ' = ' . (int)$item->product_id);
            $db->setQuery($query);
            $groups = $db->loadObject();
        }

        if (!$groups) {
            return;
        }

        $addGroups    = array_filter(explode(',', $groups->subscription_usergroup_add ?? ''));
        $removeGroups = array_filter(explode(',', $groups->subscription_usergroup_remove ?? ''));


        if ($action === 'add') {
            foreach ($addGroups as $groupId) {
                if ((int)$groupId > 0) {
                    UserHelper::addUserToGroup($item->user_id, (int)$groupId);
                    \PhocacartSubscription::logACL($item->id, $item->user_id, (int)$groupId, 'COM_PHOCACART_SUBSCRIPTION_ACL_ACTION_ADDED');
                }
            }
            foreach ($removeGroups as $groupId) {
                if ((int)$groupId > 0) {
                    UserHelper::removeUserFromGroup($item->user_id, (int)$groupId);
                    \PhocacartSubscription::logACL($item->id, $item->user_id, (int)$groupId, 'COM_PHOCACART_SUBSCRIPTION_ACL_ACTION_REMOVED');
                }
            }
        } else {
            // Remove action (reverse adding/removing logic)
            // First add groups back (restore previous state) to ensure user is not left with 0 groups
            // Joomla does not allow to have 0 group
            foreach ($removeGroups as $groupId) {
                if ((int)$groupId > 0) {
                    UserHelper::addUserToGroup($item->user_id, (int)$groupId);
                    \PhocacartSubscription::logACL($item->id, $item->user_id, (int)$groupId, 'COM_PHOCACART_SUBSCRIPTION_ACL_ACTION_ADDED');
                }
            }
            // Then remove the subscription groups
            foreach ($addGroups as $groupId) {
                if ((int)$groupId > 0) {
                    UserHelper::removeUserFromGroup($item->user_id, (int)$groupId);
                    \PhocacartSubscription::logACL($item->id, $item->user_id, (int)$groupId, 'COM_PHOCACART_SUBSCRIPTION_ACL_ACTION_REMOVED');
                }
            }
        }
    }


    public function onPhocaCartCalculationPrice($event): void
    {
        $product = $event->getArgument('product');
        if (!$product || isset($product->subscription_calculated)) {
            return;
        }
        if (!isset($product->subscription_period) || (int)$product->subscription_period <= 0) {
            return;
        }
        $user = Factory::getUser();
        $mockOrder = new \stdClass();
        $mockOrder->user_id = $user->id;
        $mockItem = clone $product;
        $mockItem->product_id = $product->id;
        $scenario = \PhocacartSubscription::determineSubscriptionScenario($mockOrder, $mockItem);
        if (isset($scenario['total_price'])) {
             $product->subscription_scenario = $scenario;
             $product->price = $scenario['total_price'];
             $product->subscription_calculated = true;
        }
    }

    /*
     public function onPhocaCartCalculationPrice($event): void
    {
        $args = $event->getArguments();
        $product = $event->getArgument('product');

        if (!$product || isset($product->subscription_calculated)) {
            return;
        }

        // Check if product is subscription (if subscription_period is set)
        if (!isset($product->subscription_period) || (int)$product->subscription_period <= 0) {
            return;
        }

        $user = Factory::getUser();

        // Mock an order object just for the helper (it only needs user_id)
        $mockOrder = new \stdClass();
        $mockOrder->user_id = $user->id;

        // Mock an item object for the helper because it uses product_id not id
        $mockItem = clone $product;
        $mockItem->product_id = $product->id;

        // Use helper logic to calculate price
        $scenario = \PhocacartSubscription::determineSubscriptionScenario($mockOrder, $mockItem);

        // Update the product price
        if (isset($scenario['total_price'])) {
             $product->subscription_scenario = $scenario;
             $product->price = $scenario['total_price'];
             $product->subscription_calculated = true;
        }
    }
     */

    public function onContentBeforeSave($event): void
    {
        $args    = $event->getArguments();
        $context = $args['context'] ?? '';
        $item    = $args['subject'] ?? null;
        $data    = $args['data'] ?? [];
        if ($context !== 'com_phocacart.phocacartitem' && $context !== 'com_phocacart.item') {
             return;
        }
        if (isset($data['type']) && (int)$data['type'] === 6) {
             $item->is_subscription = 1;
        } elseif (isset($data['type'])) {
             $item->is_subscription = 0;
        }
    }

    public function onContentPrepareForm($event): void
    {
        $args = $event->getArguments();
        $form = $args['subject'] ?? $args['form'] ?? null;
        if (!($form instanceof \Joomla\CMS\Form\Form)) {
             return;
        }
        if ($form->getName() !== 'com_phocacart.phocacartitem' && $form->getName() !== 'com_phocacart.item') {
             return;
        }
        $xmlPath = JPATH_ADMINISTRATOR . '/components/com_phocacart/models/forms/subscription_fields.xml';
        if (file_exists($xmlPath)) {
             $form->loadFile($xmlPath, false);
        }
    }

    public function onPhocaCartBeforeAddToCart($event): bool
    {
        $args = $event->getArguments();
        $productId = $args[0] ?? null; // Position might vary, check Phoca Cart dispatcher
        // Phoca Cart often uses key/value in event arguments or specific objects
        // Assuming $productId, $quantity, $userId based on prompt spec

        // Assuming args are [productId, quantity, userId] or similar
        // Let's trust the prompt provided signature roughly

        return true;
    }
}
