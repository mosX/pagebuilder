<?php

namespace App\Enums;

/**
 * Field types available for collections. Each scalar type maps to exactly one column
 * of the collection table; relation types map to a foreign key column or a pivot table.
 */
enum FieldType: string
{
    case String = 'string';
    case Text = 'text';
    case Email = 'email';
    case Url = 'url';
    case Image = 'image';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Select = 'select';
    case BelongsTo = 'belongs_to';
    case BelongsToMany = 'belongs_to_many';
    case Blocks = 'blocks';

    public function label(): string
    {
        return match ($this) {
            self::String => 'Строка',
            self::Text => 'Текст',
            self::Email => 'Email',
            self::Url => 'Ссылка',
            self::Image => 'Изображение',
            self::Integer => 'Целое число',
            self::Decimal => 'Дробное число',
            self::Boolean => 'Да / Нет',
            self::Date => 'Дата',
            self::DateTime => 'Дата и время',
            self::Select => 'Список значений',
            self::BelongsTo => 'Связь: одна запись',
            self::BelongsToMany => 'Связь: несколько записей',
            self::Blocks => 'Блоки (конструктор)',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::String, self::Text, self::Email, self::Url => 'Текст',
            self::Image => 'Контент',
            self::Integer, self::Decimal => 'Числа',
            self::Boolean, self::Date, self::DateTime, self::Select => 'Прочее',
            self::BelongsTo, self::BelongsToMany => 'Связи',
            self::Blocks => 'Контент',
        };
    }

    public function isRelation(): bool
    {
        return $this === self::BelongsTo || $this === self::BelongsToMany;
    }

    /**
     * Whether values live in a pivot table rather than a column of the collection table.
     */
    public function usesPivot(): bool
    {
        return $this === self::BelongsToMany;
    }

    public function supportsUnique(): bool
    {
        return ! $this->isRelation() && ! in_array($this, [self::Boolean, self::Text, self::Blocks, self::Image], true);
    }

    /**
     * Whether the field can serve as the human-readable title of a record.
     */
    public function canBeDisplayField(): bool
    {
        return in_array($this, [self::String, self::Email, self::Url, self::Integer, self::Select, self::Date], true);
    }

    /**
     * Whether text search should look into this field.
     */
    public function isSearchable(): bool
    {
        return in_array($this, [self::String, self::Text, self::Email, self::Url, self::Select], true);
    }

    /**
     * @return list<array{value: string, label: string, group: string, supports_unique: bool, is_relation: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'group' => $type->group(),
            'supports_unique' => $type->supportsUnique(),
            'is_relation' => $type->isRelation(),
        ], self::cases());
    }
}
