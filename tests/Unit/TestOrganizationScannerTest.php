<?php

declare(strict_types=1);

use Tests\Support\TestOrganizationScanner;

/*
 * Every source under test is a SYNTHETIC PHP string held in a nowdoc here —
 * never a real suite file (those get rewritten by each future retrofit) and
 * never a fixture. Line numbers asserted below are the 1-indexed lines of the
 * nowdoc itself, counted from its `<?php` opener.
 */

describe('outline() classification', function (): void {
    it('classifies top-level constructs in source order', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Support\Str;

        uses(RefreshDatabase::class);

        /*
         | Fixture provenance banner.
         */

        function helper(): string
        {
            return 'x';
        }

        beforeEach(function (): void {
            Str::lower('x');
        });

        describe('outline() grouping', function (): void {
            it('groups', function (): void {
                //
            });
        });
        PHP;

        // Act
        $outline = TestOrganizationScanner::outline($source);

        // Assert
        expect(array_column($outline, 'kind'))->toBe([
            'declare',
            'import',
            'uses',
            'banner',
            'function',
            'beforeEach',
            'describe',
            'it',
        ]);
    });

    it('returns an empty outline for a source declaring no constructs', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        namespace Tests\Support;

        final class Helper
        {
            public function noop(): void
            {
                //
            }
        }
        PHP;

        // Act
        $outline = TestOrganizationScanner::outline($source);

        // Assert
        expect($outline)->toBe([]);
    });
});

describe('outline() nesting', function (): void {
    it('marks an indented it() call as nested', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('outline() nesting', function (): void {
            it('is nested', function (): void {
                //
            });
        });

        it('is top level', function (): void {
            //
        });
        PHP;

        // Act
        $outline = TestOrganizationScanner::outline($source);

        // Assert
        expect(array_column($outline, 'nested'))->toBe([false, true, false]);
    });
});

describe('outline() labels', function (): void {
    it('captures each describe and it label with its line number', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('series() JWT auth', function (): void {
            it('returns a token', function (): void {
                //
            });

            it('rejects a bad key', function (): void {
                //
            });
        });
        PHP;

        // Act
        $outline = TestOrganizationScanner::outline($source);

        // Assert
        expect(array_column($outline, 'line', 'label'))->toBe([
            'series() JWT auth' => 3,
            'returns a token' => 4,
            'rejects a bad key' => 8,
        ]);
    });
});

describe('ungroupedTests()', function (): void {
    it('flags a top-level it() call with its line number', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        uses(RefreshDatabase::class);

        it('is not in a group', function (): void {
            //
        });
        PHP;

        // Act
        $ungrouped = TestOrganizationScanner::ungroupedTests($source);

        // Assert
        expect($ungrouped)->toBe([7]);
    });

    it('ignores an it() call inside a describe group', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('is grouped', function (): void {
                //
            });
        });
        PHP;

        // Act
        $ungrouped = TestOrganizationScanner::ungroupedTests($source);

        // Assert
        expect($ungrouped)->toBe([]);
    });

    it('ignores an it() call inside a nested describe group', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('an outer group', function (): void {
            describe('an inner group', function (): void {
                it('is nested twice', function (): void {
                    //
                });
            });
        });
        PHP;

        // Act
        $ungrouped = TestOrganizationScanner::ungroupedTests($source);

        // Assert
        expect($ungrouped)->toBe([]);
    });

    it('reports nothing for a source declaring no tests', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        uses(Tests\TestCase::class)->in('Feature');

        function fixtureBytes(string $path): string
        {
            return (string) file_get_contents(fixture($path));
        }
        PHP;

        // Act
        $ungrouped = TestOrganizationScanner::ungroupedTests($source);

        // Assert
        expect($ungrouped)->toBe([]);
    });
});

describe('skeletonOffenders()', function (): void {
    it('reports nothing for a conforming skeleton', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Support\Str;

        uses(RefreshDatabase::class);

        /*
         | Fixture provenance banner.
         */

        function helper(): string
        {
            return 'x';
        }

        beforeEach(function (): void {
            Str::lower('x');
        });

        describe('a group', function (): void {
            it('is grouped', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::skeletonOffenders($source);

        // Assert
        expect($offenders)->toBe([]);
    });

    it('flags a helper function declared after the first describe', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Support\Str;

        describe('a group', function (): void {
            it('is grouped', function (): void {
                //
            });
        });

        function helper(): string
        {
            return 'x';
        }
        PHP;

        // Act
        $offenders = TestOrganizationScanner::skeletonOffenders($source);

        // Assert
        expect($offenders)->toBe([['line' => 13, 'kind' => 'function']]);
    });

    it('flags a uses() call declared after a describe', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        describe('a group', function (): void {
            it('is grouped', function (): void {
                //
            });
        });

        uses(RefreshDatabase::class);
        PHP;

        // Act
        $offenders = TestOrganizationScanner::skeletonOffenders($source);

        // Assert
        expect($offenders)->toBe([['line' => 11, 'kind' => 'uses']]);
    });

    it('accepts a provenance banner sitting between the imports and uses()', function (): void {
        // Arrange
        // Both orders ship in the real suite (imports -> uses() -> banner, and
        // imports -> banner -> uses()), so a banner is rank-neutral: it never
        // offends and never raises the high-water rank for what follows it.
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Illuminate\Support\Str;

        /*
         | Fixture provenance banner.
         */

        uses(RefreshDatabase::class);

        describe('a group', function (): void {
            it('is grouped', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::skeletonOffenders($source);

        // Assert
        expect($offenders)->toBe([]);
    });

    it('ignores an indented beforeEach inside a describe group', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        describe('a group', function (): void {
            beforeEach(function (): void {
                //
            });

            it('is grouped', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::skeletonOffenders($source);

        // Assert
        expect($offenders)->toBe([]);
    });
});

describe('descriptionOffenders()', function (): void {
    it('flags a description starting with an uppercase letter', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('Returns a token', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::descriptionOffenders($source);

        // Assert
        expect($offenders)->toBe([['line' => 4, 'description' => 'Returns a token']]);
    });

    it('flags a description opening with the word should', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('should return a token', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::descriptionOffenders($source);

        // Assert
        expect($offenders)->toBe([['line' => 4, 'description' => 'should return a token']]);
    });

    it('flags a description repeated inside one describe group', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('returns a token', function (): void {
                //
            });

            it('returns a token', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::descriptionOffenders($source);

        // Assert
        expect($offenders)->toBe([['line' => 8, 'description' => 'returns a token']]);
    });

    it('accepts one description reused across two describe groups', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('the first group', function (): void {
            it('returns a token', function (): void {
                //
            });
        });

        describe('the second group', function (): void {
            it('returns a token', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::descriptionOffenders($source);

        // Assert
        expect($offenders)->toBe([]);
    });

    it('accepts a description leading with an all-caps http verb', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('GETs /updates with the since param', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::descriptionOffenders($source);

        // Assert
        expect($offenders)->toBe([]);
    });

    it('accepts a description leading with an acronym that is not an http verb', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('TMDB ids are normalized', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::descriptionOffenders($source);

        // Assert
        expect($offenders)->toBe([]);
    });

    it('flags a description leading with a single capital followed by lowercase', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('Stores the movie', function (): void {
                //
            });
        });
        PHP;

        // Act
        $offenders = TestOrganizationScanner::descriptionOffenders($source);

        // Assert
        expect($offenders)->toBe([['line' => 4, 'description' => 'Stores the movie']]);
    });
});

describe('duplicateDescribeLabels() & helperDeclarations()', function (): void {
    it('flags a describe label reused inside one file', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('is grouped', function (): void {
                //
            });
        });

        describe('a group', function (): void {
            it('is grouped again', function (): void {
                //
            });
        });
        PHP;

        // Act
        $duplicates = TestOrganizationScanner::duplicateDescribeLabels($source);

        // Assert
        expect($duplicates)->toBe([['line' => 9, 'label' => 'a group']]);
    });

    it('reports no duplicate for a file whose describe labels are all unique', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('the first group', function (): void {
            it('is grouped', function (): void {
                //
            });
        });

        describe('the second group', function (): void {
            it('is grouped too', function (): void {
                //
            });
        });
        PHP;

        // Act
        $duplicates = TestOrganizationScanner::duplicateDescribeLabels($source);

        // Assert
        expect($duplicates)->toBe([]);
    });

    it('collects each top-level helper function name with its line number', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        function fixtureBytes(string $path): string
        {
            return (string) file_get_contents(fixture($path));
        }

        function makeShow(): array
        {
            return [];
        }
        PHP;

        // Act
        $helpers = TestOrganizationScanner::helperDeclarations($source);

        // Assert
        expect($helpers)->toBe([
            ['line' => 5, 'name' => 'fixtureBytes'],
            ['line' => 10, 'name' => 'makeShow'],
        ]);
    });

    it('ignores a function declared inside a class or a block', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        function helper(): string
        {
            return 'x';
        }

        final class Support
        {
            public function method(): void
            {
                //
            }
        }

        beforeEach(function (): void {
            function nested(): void
            {
                //
            }
        });
        PHP;

        // Act
        $helpers = TestOrganizationScanner::helperDeclarations($source);

        // Assert
        expect($helpers)->toBe([['line' => 3, 'name' => 'helper']]);
    });
});

describe('tsx sources', function (): void {
    it('flags a top-level it() call written as a tsx arrow callback', function (): void {
        // Arrange
        $source = <<<'TSX'
        import { expect, it } from 'vitest';
        import Login from './Login';

        it('renders the email field', () => {
            expect(true).toBe(true);
        });
        TSX;

        // Act
        $ungrouped = TestOrganizationScanner::ungroupedTests($source);

        // Assert
        expect($ungrouped)->toBe([4]);
    });

    it('ignores a tsx it() call nested inside a describe arrow callback', function (): void {
        // Arrange
        $source = <<<'TSX'
        import { describe, expect, it } from 'vitest';
        import Login from './Login';

        describe('login page', () => {
            it('renders the email field', () => {
                expect(true).toBe(true);
            });
        });
        TSX;

        // Act
        $ungrouped = TestOrganizationScanner::ungroupedTests($source);

        // Assert
        expect($ungrouped)->toBe([]);
    });

    it('flags a top-level test() alias written as a tsx arrow callback', function (): void {
        // Arrange
        $source = <<<'TSX'
        import { expect, test } from 'vitest';
        import Login from './Login';

        test('renders the password field', () => {
            expect(true).toBe(true);
        });
        TSX;

        // Act
        $ungrouped = TestOrganizationScanner::ungroupedTests($source);

        // Assert
        expect($ungrouped)->toBe([4]);
    });

    it('applies the description form rules to a tsx source', function (): void {
        // Arrange
        $source = <<<'TSX'
        import { describe, expect, it } from 'vitest';
        import Login from './Login';

        describe('login page', () => {
            it('Renders the email field', () => {
                expect(true).toBe(true);
            });

            it('should render the password field', () => {
                expect(true).toBe(true);
            });

            it('renders a submit button', () => {
                expect(true).toBe(true);
            });

            it('renders a submit button', () => {
                expect(true).toBe(true);
            });
        });
        TSX;

        // Act
        $offenders = TestOrganizationScanner::descriptionOffenders($source);

        // Assert
        expect($offenders)->toBe([
            ['line' => 5, 'description' => 'Renders the email field'],
            ['line' => 9, 'description' => 'should render the password field'],
            ['line' => 17, 'description' => 'renders a submit button'],
        ]);
    });
});

describe('heredoc bodies', function (): void {
    it('ignores an it() call written inside a nowdoc body', function (): void {
        // The synthetic sources in this file are themselves nowdocs, so the
        // sample each Arrange embeds uses a DIFFERENT identifier (SAMPLE) —
        // only the literal `PHP` identifier closes the outer nowdoc.
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('is grouped', function (): void {
                $sample = <<<'SAMPLE'
                <?php

                it('is only a sample', function (): void {
                    //
                });
                SAMPLE;
            });
        });
        PHP;

        // Act
        $outline = TestOrganizationScanner::outline($source);

        // Assert
        expect(array_column($outline, 'label'))->toBe(['a group', 'is grouped']);
    });

    it('ignores a describe() label written inside a heredoc body', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('documents the sample', function (): void {
                $sample = <<<SAMPLE
                <?php

                describe('a group', function (): void {
                    //
                });
                SAMPLE;
            });
        });
        PHP;

        // Act
        $duplicates = TestOrganizationScanner::duplicateDescribeLabels($source);

        // Assert
        expect($duplicates)->toBe([]);
    });

    it('ignores a flush-left it() call written inside a nowdoc body', function (): void {
        // A nowdoc body written at column 0 is the shape indentation-based
        // nesting cannot rescue: without heredoc state it reads as a real
        // top-level test.
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('is grouped', function (): void {
                $sample = <<<'SAMPLE'
        <?php

        it('is written flush left', function (): void {
            //
        });
        SAMPLE;
            });
        });
        PHP;

        // Act
        $ungrouped = TestOrganizationScanner::ungroupedTests($source);

        // Assert
        expect($ungrouped)->toBe([]);
    });

    it('classifies constructs written after the closing identifier', function (): void {
        // Arrange
        $source = <<<'PHP'
        <?php

        describe('a group', function (): void {
            it('holds a sample', function (): void {
                $sample = <<<'SAMPLE'
                <?php

                it('is only a sample', function (): void {
                    //
                });
                SAMPLE;
            });

            it('runs after the sample', function (): void {
                //
            });
        });
        PHP;

        // Act
        $outline = TestOrganizationScanner::outline($source);

        // Assert
        expect(array_column($outline, 'line', 'label'))->toBe([
            'a group' => 3,
            'holds a sample' => 4,
            'runs after the sample' => 14,
        ]);
    });
});
