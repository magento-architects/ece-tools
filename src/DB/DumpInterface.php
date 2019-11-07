<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MagentoCloud\DB;

/**
 * Interface DumpInterface for generating DB dump commands
 */
interface DumpInterface
{
    /**
     * Returns DB dump command with necessary connection data and options.
     * @param string $database
     * @return string
     */
    public function getCommand(string $database): string;
}
