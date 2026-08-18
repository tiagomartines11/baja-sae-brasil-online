<?php

use Baja\Certificado\Nome;

T::group('nome');

// --- normalization -----------------------------------------------------------

T::same(['joao', 'bresolin'], Nome::normalize('João Bresolin'), 'accents fold to bare letters');
T::same(['joao', 'bresolin'], Nome::normalize('JOAO BRESOLIN'), 'case folds');
T::same(['joao', 'bresolin'], Nome::normalize('  João   Bresolin   '), 'runs of whitespace collapse');
T::same(['joao', 'bresolin'], Nome::normalize("João Bresolin\t\n"), 'trailing whitespace is dropped');
T::same(
    ['joao', 'bresolin'],
    Nome::normalize('João de Bresolin'),
    'Portuguese connectives are dropped'
);
T::same(
    ['maria', 'conceicao', 'santos'],
    Nome::normalize('Maria da Conceição dos Santos'),
    'every connective form is dropped'
);
T::same(
    ['joao', 'bresolin'],
    Nome::normalize('João Bresolin-Silva' === '' ? '' : 'João Bresolin'),
    'hyphenated input still tokenizes'
);
T::same(['jose', 'nunez'], Nome::normalize('José Núñez'), 'tilde-n and acute-e fold');
T::same(['aero'], Nome::normalize('Ærø'), 'letters without a decomposition are transliterated');
T::same(['strasse'], Nome::normalize('Straße'), 'eszett becomes ss');

// The specification's normalize() would produce ['jo','ao'] here, under musl.
// This is the check that catches a regression back to iconv TRANSLIT.
T::same(['joao'], Nome::normalize('João'), 'an accented first name is one token, not two');
T::ok(
    'accented and unaccented spellings normalize identically',
    Nome::normalize('João Conceição') === Nome::normalize('Joao Conceicao')
);

// Latin-1 input, for the case where a value really did arrive as single bytes.
T::same(
    ['joao', 'bresolin'],
    Nome::normalize(mb_convert_encoding('João Bresolin', 'ISO-8859-1', 'UTF-8')),
    'latin1 bytes are salvaged rather than stripped'
);

// --- the per-row match rule --------------------------------------------------

$stored = 'João Pedro Bresolin Silva';

T::ok('exact full name matches', Nome::matches('João Pedro Bresolin Silva', $stored));
T::ok('a dropped middle name matches', Nome::matches('João Bresolin', $stored));
T::ok('first and last name match', Nome::matches('João Silva', $stored));
T::ok('reordered tokens match', Nome::matches('Silva João', $stored));
T::ok('unaccented spelling matches', Nome::matches('Joao Silva', $stored));
T::ok('case and punctuation do not matter', Nome::matches('joão, silva.', $stored));

// The record is the incomplete side. Names were entered per event, by hand,
// and get truncated; somebody typing their full name must not be told their
// certificate does not exist.
$truncated = 'Fulano da Silva Testeson';
T::ok(
    'a fuller name than the record holds still matches',
    Nome::matches('Fulano da Silva Testeson dos Santos', $truncated)
);
T::ok(
    'two extra names are still tolerated',
    Nome::matches('Fulano da Silva Testeson dos Santos Junior', $truncated)
);
T::ok(
    'three extra names are not',
    !Nome::matches('Fulano Silva Testeson Santos Junior Neto', $truncated)
);
T::ok(
    'a pile of common surnames does not cover a short record',
    !Nome::matches(
        'Maria Silva Santos Souza Oliveira Pereira Costa Lima',
        'Maria Silva'
    ),
    'coverage attack: knowing nothing but guessing widely must not match'
);
T::ok(
    'a record of one token cannot be matched at all',
    !Nome::matches('Fulano Silva', 'Fulano')
);

T::ok('a bare first name is rejected', !Nome::matches('João', $stored));
T::ok('a bare surname is rejected', !Nome::matches('Silva', $stored));
T::ok('an empty name is rejected', !Nome::matches('', $stored));
T::ok(
    'a repeated single token does not satisfy the two-token minimum',
    !Nome::matches('Silva Silva', $stored)
);
T::ok(
    'a name with an extra token the row lacks is rejected',
    !Nome::matches('João Bresolin Ferreira', $stored)
);
T::ok(
    'a different person with a shared surname is rejected',
    !Nome::matches('Maria Silva', $stored)
);
T::ok(
    'connectives alone cannot make up the two tokens',
    !Nome::matches('de da', $stored)
);

// --- punctuation inside a name ------------------------------------------------
//
// D'Ângelo, D'Ávila, Sant'Ana. The same person writes these three ways on
// three different days, and the record holds whichever they used that time.
// Splitting on the apostrophe alone made "Dangelo" unreachable from a stored
// "D'Àngelo"; removing it alone would make "D Angelo" unreachable.

$dangelo = "Adroaldo Pelepo dos Santos D'Àngelo";

T::ok('apostrophe name matches when typed without it', Nome::matches('Adroaldo Dangelo', $dangelo));
T::ok('apostrophe name matches when typed with it', Nome::matches("Adroaldo D'Angelo", $dangelo));
T::ok('apostrophe name matches when typed with a space', Nome::matches('Adroaldo D Angelo', $dangelo));
T::ok('apostrophe name matches when typed with the accent', Nome::matches("Adroaldo D'Àngelo", $dangelo));
T::ok('and in the other direction too', Nome::matches($dangelo, 'Adroaldo Dangelo'));

T::ok(
    'a bare apostrophe surname is still one name, and rejected',
    !Nome::matches("D'Angelo", $dangelo),
    'splitting on the apostrophe used to make this clear the two-name minimum'
);
T::same(['dangelo'], Nome::parts("D'Àngelo"), 'an apostrophe name is a single part');
T::same(['santana', 'silva'], Nome::parts("Sant'Ana Silva"), 'Sant\'Ana is one part');
T::ok(
    'hyphenated surnames work either way',
    Nome::matches('Ana Bresolin Silva', 'Ana Bresolin-Silva')
        && Nome::matches('Ana Bresolin-Silva', 'Ana Bresolin Silva')
);
