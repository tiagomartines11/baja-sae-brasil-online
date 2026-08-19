<?php

use Baja\Certificado\Insercao\Padrao;

T::group('padrao');

// --- the wildcard ---------------------------------------------------------------
//
// `*`, because it is what people type. Everything is a substring match, so a
// surname alone finds the person without anybody having to know that.

T::same('%Silva%', Padrao::paraNome('Silva'), 'a bare term matches as a substring');
T::same('%Jose%Silva%', Padrao::paraNome('Jose*Silva'), 'a star becomes a wildcard');
T::same('%Jose%Silva%', Padrao::paraNome('*Jose*Silva*'), 'leading and trailing stars are already implied');
T::same('%Jose%Silva%', Padrao::paraNome('Jose**Silva'), 'a doubled star is still one wildcard');
T::same('%Jose%Antonio%Silva%', Padrao::paraNome('Jose*Antonio*Silva'), 'several wildcards');

T::same(null, Padrao::paraNome(''), 'an empty term is not a filter');
T::same(null, Padrao::paraNome('   '), 'whitespace alone is not a filter');
T::same(null, Padrao::paraNome('*'), 'a lone wildcard is not a filter');
T::same(null, Padrao::paraNome('***'), 'nor several of them');

// --- LIKE's own metacharacters ------------------------------------------------------
//
// Somebody searching for a literal % wants rows containing one, not the whole
// table. This is the difference between a search box and an injection.

T::same('%100\\%%', Padrao::paraNome('100%'), 'a literal percent is escaped');
T::same('%a\\_b%', Padrao::paraNome('a_b'), 'so is an underscore');
T::same('%a\\\\b%', Padrao::paraNome('a\\b'), 'and a backslash');
T::same('%100\\%%off%', Padrao::paraNome('100%*off'), 'escaping survives alongside a real wildcard');

// --- apostrophes ---------------------------------------------------------------------
//
// The same person writes them inconsistently on different days, and the search
// box is where that bites. Accents and case are not handled here at all: the
// column's collation ignores both, in both directions.

T::same('%DAvila%', Padrao::paraNome("D'Avila"), 'a straight apostrophe comes out');
T::same('%DAvila%', Padrao::paraNome("D\u{2019}Avila"), 'and a curly one');
T::same('%DAvila%', Padrao::paraNome('DAvila'), 'a term written without one is unchanged');
T::same('%SantAna%', Padrao::paraNome("Sant'Ana"), "Sant'Ana");
T::same('%José%', Padrao::paraNome('José'), 'accents are left for the collation to ignore');

// --- documents -------------------------------------------------------------------------

T::same('%52998224725%', Padrao::paraCpf('529.982.247-25'), 'a punctuated CPF reduces to digits');
T::same('%5299%', Padrao::paraCpf('5299'), 'a fragment stays a fragment');
T::same('%529%247%', Padrao::paraCpf('529*247'), 'wildcards survive the punctuation strip');
T::same('%1234567890%', Padrao::paraCpf('1234567890'), 'a CPF missing its leading zeros is a substring of the padded one');
T::same(null, Padrao::paraCpf('AB'), 'letters alone give no CPF pattern');
T::same('%123%', Padrao::paraCpf('AB123'), 'and letters are dropped from a mixed term');

T::same('%AB123456%', Padrao::paraPassaporte('AB-123.456'), 'a punctuated passport keeps its letters');
// Case is left alone rather than folded, because documento_estrangeiro is
// utf8mb4_unicode_ci and already ignores it — verified live: this pattern
// finds a row stored as "AB-123.456". Folding here would be a second rule
// that has to agree with the collation.
T::same('%ab123456%', Padrao::paraPassaporte('ab 123 456'), 'spaces come out and case is left to the collation');
T::same('%AB%456%', Padrao::paraPassaporte('AB*456'), 'wildcards survive there as well');
T::same(null, Padrao::paraPassaporte('---'), 'punctuation alone is not a filter');
