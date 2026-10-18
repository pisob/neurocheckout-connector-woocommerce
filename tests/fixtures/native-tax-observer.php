<?php
namespace Automattic\WooCommerce\Admin\Features\OnboardingTasks\Tasks;
class Tax {
    public function on_tax_rate_added() { throw new \RuntimeException('observer_must_not_run'); }
    public function on_tax_rate_deleted() { throw new \RuntimeException('observer_must_not_run'); }
}
