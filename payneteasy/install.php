<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;

class PlgvmpaymentpayneteasyInstallerScript {
	private const GROUP = 'vmpayment';
	private const ELEMENT = 'payneteasy';

	public function postflight($type, $parent) {
			if ($type == 'uninstall')
				return;

			$db = Factory::getDbo();

			try {
				if (!($extensionId = $this->findExtensionId($db)))
						return;

				$this->enablePlugin($db, $extensionId);
				$this->createPluginTable($db);

				if ($paymentMethodId = $this->createPaymentMethodIfMissing($db, $extensionId, (string)$parent->getManifest()->version))
					$parent->getParent()->message = 'Open the Payneteasy <a class="text-decoration-underline" href="'.Route::_("index.php?option=com_virtuemart&view=paymentmethod&task=edit&cid[]=$paymentMethodId").'">payment method settings</a>';
			}
			catch (\Throwable $e) {
				Factory::getApplication()->enqueueMessage(
					'Payneteasy: automatic setup after install did not fully complete ('.$e->getMessage().'). You can still finish it manually via Configuration -> Payment Methods -> New.',
					'warning');
			}
	}

	private function findExtensionId($db): int {
		return (int)$db->setQuery($db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type').'='.$db->quote('plugin'))
			->where($db->quoteName('element').'='.$db->quote(self::ELEMENT))
			->where($db->quoteName('folder').'='.$db->quote(self::GROUP))
		)->loadResult();
	}

	private function enablePlugin($db, int $extensionId): void {
		$query = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('enabled').'=1')
			->where($db->quoteName('extension_id').'='.$extensionId);

		$db->setQuery($query)->execute();
	}

	private function createPaymentMethodIfMissing($db, int $extensionId, string $version): ?int {
			if (!($tables = $db->getTableList()) || !in_array($db->replacePrefix('#__virtuemart_paymentmethods'), $tables))
				return null;

			$query = $db->getQuery(true)
				->select($db->quoteName('virtuemart_paymentmethod_id'))
				->from($db->quoteName('#__virtuemart_paymentmethods'))
				->where($db->quoteName('payment_element').'='.$db->quote(self::ELEMENT));

			if ($existingId = (int)$db->setQuery($query)->loadResult()) {
				$db->setQuery($db->getQuery(true)
					->update($db->quoteName('#__virtuemart_paymentmethods'))
					->set($db->quoteName('payment_jplugin_id').'='.$extensionId)
					->where($db->quoteName('virtuemart_paymentmethod_id').'='.$existingId)
				)->execute();

				$this->createPaymentMethodLangRows($db, $existingId, $tables, $version);

				return $existingId;
			}

			$db->setQuery($db->getQuery(true)
				->insert($db->quoteName('#__virtuemart_paymentmethods'))
				->columns([
					$db->quoteName('virtuemart_vendor_id'),
					$db->quoteName('payment_jplugin_id'),
					$db->quoteName('payment_element'),
					$db->quoteName('published'),
					$db->quoteName('created_on') ])
				->values(join(',', [ 1, $extensionId, $db->quote(self::ELEMENT), 1, $db->quote(Factory::getDate()->toSql()) ]))
			)->execute();

			$this->createPaymentMethodLangRows($db, $newId = (int)$db->insertid(), $tables, $version);

			return $newId;
	}

	private function createPaymentMethodLangRows($db, int $paymentMethodId, array $tables, string $version): void {
		foreach ($tables as $table) {
			if (strpos($table, $prefix ??= $db->replacePrefix('#__virtuemart_paymentmethods_')) !== 0)
				continue;

			$db->setQuery(
				$db->getQuery(true)
					->delete($db->quoteName($table))
					->where($db->quoteName('slug').'='.$db->quote(self::ELEMENT))
			)->execute();

			$query = $db->getQuery(true)
				->insert($db->quoteName($table))
				->columns([
					$db->quoteName('virtuemart_paymentmethod_id'),
					$db->quoteName('payment_name'),
					$db->quoteName('payment_desc'),
					$db->quoteName('slug') ])
				->values(join(',', [ $paymentMethodId, $db->quote("Payneteasy v$version"), $db->quote(''), $db->quote(self::ELEMENT) ]));

			$db->setQuery($query)->execute();
		}
	}

	private function createPluginTable($db): void {
		$columns = [
			'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' => 'INT UNSIGNED',
			'virtuemart_paymentmethod_id' => 'INT UNSIGNED',
			'payment_name' => 'VARCHAR(16)',
			'payneteasy_order_id' => 'VARCHAR(48)',
			'payneteasy_status' => 'VARCHAR(48)',
			'payneteasy_descriptor' => 'VARCHAR(128)',
			'created_on' => 'DATETIME',
			'created_by' => 'INT(11) NOT NULL DEFAULT 0',
			'modified_on' => 'DATETIME',
			'modified_by' => 'INT(11) NOT NULL DEFAULT 0',
			'locked_on' => 'DATETIME',
			'locked_by' => 'INT(11) NOT NULL DEFAULT 0' ];

		foreach ($columns as $name => $type)
			$definitions[] = $db->quoteName($name).' '.$type;

		$definitions[] = 'PRIMARY KEY ('.$db->quoteName('id').')';
		$definitions[] = 'KEY '.$db->quoteName('virtuemart_order_id').' ('.$db->quoteName('virtuemart_order_id').')';

		$db->setQuery('CREATE TABLE IF NOT EXISTS '.$db->quoteName('#__virtuemart_payment_plg_payneteasy').' ('.join(',', $definitions).') DEFAULT CHARSET=utf8mb4')
			->execute();
	}
}
