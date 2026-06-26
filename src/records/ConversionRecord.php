<?php

declare(strict_types=1);

namespace livehand\abtestcraft\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Conversion event record.
 *
 * @property int $id
 * @property int $testId
 * @property string $visitorId
 * @property string $variant
 * @property string $conversionType
 * @property int|null $goalId
 * @property string $dateConverted
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ConversionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%abtestcraft_conversions}}';
    }

    public function getTest(): ActiveQueryInterface
    {
        return $this->hasOne(TestRecord::class, ['id' => 'testId']);
    }
}
