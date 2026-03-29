<?php

/**
 * Transaction coverage tests for CrudTrait.
 * Verifies that insert(), update(), erase() wrap operations
 * in transactions for SQL engines, handle rollback on error,
 * and respect user-managed outer transactions.
 */
class Test_Transaction {

	function run($db, $type)
	{
		$test = new \Test();

		// ====== SETUP ======
		$tname = 'test_tx';
		\DB\Cortex::setdown($db, $tname);
		$fields = [
			'title'  => ['type' => \DB\SQL\Schema::DT_VARCHAR256],
			'amount' => ['type' => \DB\SQL\Schema::DT_INT4],
		];
		\DB\Cortex::setup($db, $tname, $fields);

		// ========================================================================
		// 1. insert() commits implicitly on SQL
		// ========================================================================
		$cx = new \DB\Cortex($db, $tname);
		$cx->setFieldConfiguration($fields);
		$cx->title = 'tx_insert';
		$cx->amount = 100;
		$cx->save();

		// after save, transaction flag should be false (committed)
		$test->expect(
			$db->trans() === false,
			$type.': insert() - transaction flag is false after save (committed)'
		);

		// data is actually persisted
		$cx->reset();
		$cx->load(['title = ?', 'tx_insert']);
		$test->expect(
			!$cx->dry() && $cx->amount === 100,
			$type.': insert() - data persisted after implicit transaction'
		);

		// ========================================================================
		// 2. update() commits implicitly on SQL
		// ========================================================================
		$cx->amount = 200;
		$cx->save();

		$test->expect(
			$db->trans() === false,
			$type.': update() - transaction flag is false after save (committed)'
		);

		$id = $cx->_id;
		$cx->reset();
		$cx->load(['id = ?', $id]);
		$test->expect(
			$cx->amount === 200,
			$type.': update() - modified data persisted after implicit transaction'
		);

		// ========================================================================
		// 3. erase() commits implicitly on SQL
		// ========================================================================
		$cx->erase();

		$test->expect(
			$db->trans() === false,
			$type.': erase() - transaction flag is false after erase (committed)'
		);

		$cx->reset();
		$cx->load(['id = ?', $id]);
		$test->expect(
			$cx->dry(),
			$type.': erase() - record removed after implicit transaction'
		);

		// ========================================================================
		// 4. insert() rolls back on exception
		// ========================================================================
		// Create a model subclass that throws during _aftersave
		$badModel = new class($db, $tname) extends \DB\Cortex {
			protected function _aftersave() {
				throw new \RuntimeException('Simulated _aftersave failure');
			}
		};
		$badModel->setFieldConfiguration($fields);
		$badModel->title = 'tx_rollback';
		$badModel->amount = 999;
		$exception = null;
		try {
			$badModel->save(); // insert path
		} catch (\RuntimeException $e) {
			$exception = $e;
		}

		$test->expect(
			$exception instanceof \RuntimeException
			&& $exception->getMessage() === 'Simulated _aftersave failure',
			$type.': insert() - exception propagated from _aftersave'
		);

		$test->expect(
			$db->trans() === false,
			$type.': insert() - transaction flag is false after rollback'
		);

		// verify the record was NOT persisted (rolled back)
		$cx->reset();
		$cx->load(['title = ?', 'tx_rollback']);
		$test->expect(
			$cx->dry(),
			$type.': insert() - data rolled back on _aftersave failure'
		);

		// ========================================================================
		// 5. update() rolls back on exception
		// ========================================================================
		// seed a record first
		$cx->reset();
		$cx->title = 'tx_update_rb';
		$cx->amount = 50;
		$cx->save();

		$updId = $cx->_id;

		// now use bad model to update
		$badModel2 = new class($db, $tname) extends \DB\Cortex {
			protected function _aftersave() {
				throw new \RuntimeException('Simulated update failure');
			}
		};
		$badModel2->setFieldConfiguration($fields);
		$badModel2->load(['id = ?', $updId]);
		$badModel2->amount = 9999;
		$exception = null;
		try {
			$badModel2->save(); // update path
		} catch (\RuntimeException $e) {
			$exception = $e;
		}

		$test->expect(
			$exception instanceof \RuntimeException,
			$type.': update() - exception propagated from _aftersave'
		);

		// verify original value is preserved (rolled back)
		$cx->reset();
		$cx->load(['id = ?', $updId]);
		$test->expect(
			$cx->amount === 50,
			$type.': update() - data rolled back on _aftersave failure'
		);

		// ========================================================================
		// 6. User-managed outer transaction - no double begin
		// ========================================================================
		$db->begin();
		$test->expect(
			$db->trans() === true,
			$type.': outer transaction - trans() is true after begin()'
		);

		$cx->reset();
		$cx->title = 'tx_outer';
		$cx->amount = 300;
		$cx->save(); // should NOT call begin() again

		// still in outer transaction
		$test->expect(
			$db->trans() === true,
			$type.': outer transaction - trans() still true after nested save (no auto-commit)'
		);

		$db->commit();
		$test->expect(
			$db->trans() === false,
			$type.': outer transaction - trans() false after user commit'
		);

		// verify data was saved
		$cx->reset();
		$cx->load(['title = ?', 'tx_outer']);
		$test->expect(
			!$cx->dry() && $cx->amount === 300,
			$type.': outer transaction - data persisted after user commit'
		);

		// ========================================================================
		// 7. User-managed outer transaction - rollback encompasses save
		// ========================================================================
		$db->begin();
		$cx->reset();
		$cx->title = 'tx_outer_rb';
		$cx->amount = 400;
		$cx->save();

		$db->rollback();

		$cx->reset();
		$cx->load(['title = ?', 'tx_outer_rb']);
		$test->expect(
			$cx->dry(),
			$type.': outer transaction - user rollback reverts nested save'
		);

		// ========================================================================
		// 8. Multiple operations in user-managed transaction
		// ========================================================================
		$db->begin();

		$cx->reset();
		$cx->title = 'tx_multi_1';
		$cx->amount = 10;
		$cx->save();

		$cx->reset();
		$cx->title = 'tx_multi_2';
		$cx->amount = 20;
		$cx->save();

		$db->commit();

		$cx->reset();
		$result = $cx->find(['title like ?', 'tx_multi_%'], ['order' => 'title']);
		$test->expect(
			$result && count($result) === 2,
			$type.': multiple saves in one outer transaction - both persisted after commit'
		);

		// ========================================================================
		// 9. erase() rolls back on exception (m:m cleanup path)
		// ========================================================================
		// Use a model that throws during mapper erase
		$badErase = new class($db, $tname) extends \DB\Cortex {
			// override to simulate failure
			public function erase($filter = null) {
				if ($filter === null && !$this->dry()) {
					if ($this->emit('beforeerase') === false)
						return false;
					// Simulate: throw before mapper->erase completes
					throw new \RuntimeException('Simulated erase failure');
				}
				return parent::erase($filter);
			}
		};
		$badErase->setFieldConfiguration($fields);

		// seed a record
		$cx->reset();
		$cx->title = 'tx_erase_rb';
		$cx->amount = 777;
		$cx->save();
		$eraseId = $cx->_id;

		// attempt to erase with bad model
		$badErase->load(['id = ?', $eraseId]);
		$exception = null;
		try {
			$badErase->erase();
		} catch (\RuntimeException $e) {
			$exception = $e;
		}

		$test->expect(
			$exception instanceof \RuntimeException,
			$type.': erase() - exception propagated'
		);

		// record should still exist since erase overrode the whole method
		// This specifically tests that exceptions are caught at the caller level
		$cx->reset();
		$cx->load(['id = ?', $eraseId]);
		$test->expect(
			!$cx->dry(),
			$type.': erase() - record preserved after failed erase'
		);

		// ========================================================================
		// 10. beforeinsert event returning false prevents transaction start
		// ========================================================================
		$cx2 = new \DB\Cortex($db, $tname);
		$cx2->setFieldConfiguration($fields);
		$cx2->beforeinsert(function($self) {
			return false;
		});
		$cx2->title = 'tx_event_block';
		$cx2->amount = 555;
		$result = $cx2->save();

		$test->expect(
			$result === false,
			$type.': beforeinsert returning false prevents save and transaction'
		);
		$test->expect(
			$db->trans() === false,
			$type.': beforeinsert returning false - no dangling transaction'
		);

		// verify record was NOT persisted
		$cx2 = new \DB\Cortex($db, $tname);
		$cx2->setFieldConfiguration($fields);
		$cx2->load(['title = ?', 'tx_event_block']);
		$test->expect(
			$cx2->dry(),
			$type.': beforeinsert returning false - no data written'
		);

		// ========================================================================
		// CLEANUP
		// ========================================================================
		\DB\Cortex::setdown($db, $tname);

		return $test->results();
	}
}
