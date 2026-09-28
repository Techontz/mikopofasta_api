<?php

namespace Tests\Unit\Customers;

use App\Services\Customers\HistoricalNameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deciding when two names printed by the old system are the same person, so the historical imports neither duplicate
 * a customer nor merge two.
 */
class HistoricalNameMatcherTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function samePerson(): array
    {
        return [
            'an initial stands for the middle name' => ['SHABAN DAUD MASONJO', 'SHABAN D. MASONJO'],
            'a two-word first name keeps its initial' => ['ALFONCE TEST GOTROP SINKONGE', 'ALFONCE TEST G. SINKONGE'],
            'a blank initial on the printout' => ['RIZIKI JORAM', 'RIZIKI . JORAM'],
            'no middle name recorded' => ['LUCAS MANINGU KASUBI', 'LUCAS KASUBI'],
            'case and dots do not matter' => ['lucas m. kasubi', 'LUCAS MANINGU KASUBI'],
            'an apostrophe is part of the name' => ["LEAH WHYTONE MDONG'ALA", "LEAH W. MDONG'ALA"],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function differentPeople(): array
    {
        return [
            'a different surname' => ['SUZANA JOSEPH MALAGO', 'SUZANA J. JOHN'],
            'a different first name' => ['JASTIN OMUJUN JOHN', 'CHARLES O. JOHN'],
            'spellings that only look alike are never merged' => ['MARIAM HARUNA MUSSA', 'MARIAMU H. MUSSA'],
            'a doubled letter is a different name' => ['DOTO MSIBA BUNOKO', 'DOTTO M. BUNOKO'],
            'a middle initial that disagrees' => ['SHABAN DAUD MASONJO', 'SHABAN P. MASONJO'],
            'a surname alone is nobody' => ['MASONJO', 'SHABAN D. MASONJO'],
        ];
    }

    #[DataProvider('samePerson')]
    public function test_it_recognises_the_same_person(string $printed, string $recorded): void
    {
        $this->assertTrue(HistoricalNameMatcher::same($printed, $recorded));
        $this->assertTrue(HistoricalNameMatcher::same($recorded, $printed));
        $this->assertSame(HistoricalNameMatcher::key($printed), HistoricalNameMatcher::key($recorded));
    }

    #[DataProvider('differentPeople')]
    public function test_it_keeps_different_people_apart(string $printed, string $recorded): void
    {
        $this->assertFalse(HistoricalNameMatcher::same($printed, $recorded));
        $this->assertFalse(HistoricalNameMatcher::same($recorded, $printed));
    }

    public function test_it_reads_a_printed_name_as_words(): void
    {
        $this->assertSame(['ALFONCE', 'TEST', 'G', 'SINKONGE'], HistoricalNameMatcher::parts('ALFONCE TEST G. SINKONGE'));
        $this->assertSame(['RIZIKI', 'JORAM'], HistoricalNameMatcher::parts('RIZIKI . JORAM'));
        $this->assertSame('SINKONGE|ALFONCE', HistoricalNameMatcher::key('ALFONCE TEST G. SINKONGE'));
    }
}
