<?php

namespace FriendsOfRedaxo\VTrans\Provider;

use FriendsOfRedaxo\VTrans\VTransProviderInterface;
use FriendsOfRedaxo\VTrans\VTransProviderResult;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use rex_exception;

/**
 * Local fake provider for development.
 *
 * Simulates translation offline with a very small built-in dictionary.
 * Supported formats: text, html
 * Supported languages: de, en, es, fr, it
 */
class VTransFakeLocalProvider implements VTransProviderInterface
{
	private const API = 'fake-local-v1';

	/** @var array<string, bool> */
	private const SUPPORTED_LANGS = [
		'de' => true,
		'en' => true,
		'es' => true,
		'fr' => true,
		'it' => true,
	];

	/**
	 * Small multi-language concept dictionary.
	 * Key = concept-id, value = translation by language code.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const LEXICON = [
		'about'     => ['de' => 'über',     'es' => 'sobre',      'fr' => 'sur',         'it' => 'su'],
		'after'     => ['de' => 'nach',     'es' => 'después',    'fr' => 'après',       'it' => 'dopo'],
		'again'     => ['de' => 'wieder',     'es' => 'de nuevo',   'fr' => 'encore',      'it' => 'di nuovo'],
		'all'       => ['de' => 'alle',       'es' => 'todo',       'fr' => 'tout',        'it' => 'tutto'],
		'also'      => ['de' => 'auch',      'es' => 'también',    'fr' => 'aussi',       'it' => 'anche'],
		'and'       => ['de' => 'und',       'es' => 'y',          'fr' => 'et',          'it' => 'e'],
		'any'       => ['de' => 'irgendein',       'es' => 'cualquier',  'fr' => 'quelque',     'it' => 'qualsiasi'],
		'are'       => ['de' => 'sind',       'es' => 'son',        'fr' => 'êtes',        'it' => 'sono'],
		'as'        => ['de' => 'als',        'es' => 'como',       'fr' => 'comme',       'it' => 'come'],
		'at'        => ['de' => 'bei',        'es' => 'en',         'fr' => 'à',           'it' => 'a'],
		'back'      => ['de' => 'zurück',      'es' => 'atrás',      'fr' => 'arrière',     'it' => 'indietro'],
		'be'        => ['de' => 'sein',        'es' => 'ser',        'fr' => 'être',        'it' => 'essere'],
		'because'   => ['de' => 'weil',   'es' => 'porque',     'fr' => 'parce que',   'it' => 'perché'],
		'before'    => ['de' => 'vor',    'es' => 'antes',      'fr' => 'avant',       'it' => 'prima'],
		'between'   => ['de' => 'zwischen',   'es' => 'entre',      'fr' => 'entre',       'it' => 'tra'],
		'but'       => ['de' => 'aber',       'es' => 'pero',       'fr' => 'mais',        'it' => 'ma'],
		'by'        => ['de' => 'durch',        'es' => 'por',        'fr' => 'par',         'it' => 'da'],
		'can'       => ['de' => 'kann',       'es' => 'puede',      'fr' => 'peut',        'it' => 'può'],
		'company'   => ['de' => 'unternehmen',  'es' => 'empresa',    'fr' => 'entreprise',  'it' => 'azienda'],
		'contact'   => ['de' => 'kontakt',   'es' => 'contacto',   'fr' => 'contact',     'it' => 'contatto'],
		'cooling'   => ['de' => 'kälte',   'es' => 'refrigeración','fr' => 'refroidissement','it' => 'raffreddamento'],
		'day'       => ['de' => 'tag',       'es' => 'día',        'fr' => 'jour',        'it' => 'giorno'],
		'do'        => ['de' => 'tun',        'es' => 'hacer',      'fr' => 'faire',       'it' => 'fare'],
		'energy'    => ['de' => 'energie',    'es' => 'energía',    'fr' => 'énergie',     'it' => 'energia'],
		'even'      => ['de' => 'sogar',      'es' => 'incluso',    'fr' => 'même',        'it' => 'persino'],
		'feedback'  => ['de' => 'feedback',  'es' => 'opinión',    'fr' => 'retour',      'it' => 'feedback'],
		'first'     => ['de' => 'erste',     'es' => 'primero',    'fr' => 'premier',     'it' => 'primo'],
		'for'       => ['de' => 'für',       'es' => 'para',       'fr' => 'pour',        'it' => 'per'],
		'from'      => ['de' => 'von',      'es' => 'de',         'fr' => 'de',          'it' => 'da'],
		'germany'   => ['de' => 'deutschland',  'es' => 'alemania',   'fr' => 'allemagne',   'it' => 'germania'],
		'get'       => ['de' => 'bekommen',       'es' => 'obtener',    'fr' => 'obtenir',     'it' => 'ottenere'],
		'good'      => ['de' => 'gut',      'es' => 'bueno',      'fr' => 'bon',         'it' => 'buono'],
		'have'      => ['de' => 'haben',      'es' => 'tener',      'fr' => 'avoir',       'it' => 'avere'],
		'heat'      => ['de' => 'wärme',      'es' => 'calor',      'fr' => 'chaleur',     'it' => 'calore'],
		'hello'     => ['de' => 'hallo',     'es' => 'hola',       'fr' => 'bonjour',     'it' => 'ciao'],
		'here'      => ['de' => 'hier',      'es' => 'aquí',       'fr' => 'ici',         'it' => 'qui'],
		'how'       => ['de' => 'wie',       'es' => 'cómo',       'fr' => 'comment',     'it' => 'come'],
		'i'         => ['de' => 'ich',         'es' => 'yo',         'fr' => 'je',          'it' => 'io'],
		'if'        => ['de' => 'wenn',        'es' => 'si',         'fr' => 'si',          'it' => 'se'],
		'imprint'   => ['de' => 'impressum',   'es' => 'aviso legal','fr' => 'mentions légales','it' => 'note legali'],
		'in'        => ['de' => 'in',        'es' => 'en',         'fr' => 'dans',        'it' => 'in'],
		'is'        => ['de' => 'ist',        'es' => 'es',         'fr' => 'est',         'it' => 'è'],
		'it'        => ['de' => 'es',        'es' => 'lo',         'fr' => 'il',          'it' => 'esso'],
		'know'      => ['de' => 'wissen',      'es' => 'saber',      'fr' => 'savoir',      'it' => 'sapere'],
		'last'      => ['de' => 'letzte',      'es' => 'último',     'fr' => 'dernier',     'it' => 'ultimo'],
		'like'      => ['de' => 'mögen',      'es' => 'gustar',     'fr' => 'aimer',       'it' => 'piacere'],
		'look'      => ['de' => 'schauen',      'es' => 'mirar',      'fr' => 'regarder',    'it' => 'guardare'],
		'make'      => ['de' => 'machen',      'es' => 'hacer',      'fr' => 'faire',       'it' => 'fare'],
		'many'      => ['de' => 'viele',      'es' => 'muchos',     'fr' => 'beaucoup',    'it' => 'molti'],
		'me'        => ['de' => 'mir',        'es' => 'me',         'fr' => 'moi',         'it' => 'me'],
		'more'      => ['de' => 'mehr',      'es' => 'más',        'fr' => 'plus',        'it' => 'più'],
		'my'        => ['de' => 'mein',        'es' => 'mi',         'fr' => 'mon',         'it' => 'mio'],
		'new'       => ['de' => 'neu',       'es' => 'nuevo',      'fr' => 'nouveau',     'it' => 'nuovo'],
		'no'        => ['de' => 'nein',        'es' => 'no',         'fr' => 'non',         'it' => 'no'],
		'not'       => ['de' => 'nicht',       'es' => 'no',         'fr' => 'ne...pas',    'it' => 'non'],
		'now'       => ['de' => 'jetzt',       'es' => 'ahora',      'fr' => 'maintenant',  'it' => 'adesso'],
		'of'        => ['de' => 'von',        'es' => 'de',         'fr' => 'de',          'it' => 'di'],
		'on'        => ['de' => 'auf',        'es' => 'en',         'fr' => 'sur',         'it' => 'su'],
		'one'       => ['de' => 'eins',       'es' => 'uno',        'fr' => 'un',          'it' => 'uno'],
		'or'        => ['de' => 'oder',        'es' => 'o',          'fr' => 'ou',          'it' => 'o'],
		'our'       => ['de' => 'unser',       'es' => 'nuestro',    'fr' => 'notre',       'it' => 'nostro'],
		'out'       => ['de' => 'aus',       'es' => 'fuera',      'fr' => 'dehors',      'it' => 'fuori'],
		'people'    => ['de' => 'leute',    'es' => 'gente',      'fr' => 'gens',        'it' => 'gente'],
		'power'     => ['de' => 'strom',     'es' => 'energía eléctrica','fr' => 'électricité','it' => 'energia elettrica'],
		'privacy'   => ['de' => 'datenschutz',  'es' => 'privacidad', 'fr' => 'confidentialité','it' => 'privacy'],
		'sales'     => ['de' => 'vertrieb',     'es' => 'ventas',     'fr' => 'ventes',      'it' => 'vendite'],
		'service'   => ['de' => 'service',   'es' => 'servicio',   'fr' => 'service',     'it' => 'servizio'],
		'some'      => ['de' => 'einige',      'es' => 'algunos',    'fr' => 'quelques',    'it' => 'alcuni'],
		'systems'   => ['de' => 'anlagen',   'es' => 'sistemas',   'fr' => 'systèmes',    'it' => 'impianti'],
		'team'      => ['de' => 'team',      'es' => 'equipo',     'fr' => 'équipe',      'it' => 'team'],
		'that'      => ['de' => 'dass',      'es' => 'que',        'fr' => 'que',         'it' => 'che'],
		'the'       => ['de' => 'der/die/das',      'es' => 'el/la/los/las','fr' => 'le/la/les',   'it' => 'il/la/i/gli/le'],
		'their'     => ['de' => 'ihr',     'es' => 'su',         'fr' => 'leur',        'it' => 'loro'],
		'there'     => ['de' => 'dort',     'es' => 'allí',       'fr' => 'là',          'it' => 'lì'],
		'they'      => ['de' => 'sie',      'es' => 'ellos',      'fr' => 'ils',         'it' => 'loro'],
		'this'      => ['de' => 'dies',      'es' => 'este',       'fr' => 'ce',          'it' => 'questo'],
		'time'      => ['de' => 'zeit',      'es' => 'tiempo',     'fr' => 'temps',       'it' => 'tempo'],
		'to'        => ['de' => 'zu',        'es' => 'a',          'fr' => 'à',           'it' => 'a'],
		'up'        => ['de' => 'hoch',        'es' => 'arriba',     'fr' => 'haut',        'it' => 'su'],
		'ventilation'=> ['de' => 'lüftung','es' => 'ventilación','fr' => 'ventilation','it' => 'ventilazione'],
		'very'      => ['de' => 'sehr',      'es' => 'muy',        'fr' => 'très',        'it' => 'molto'],
		'we'        => ['de' => 'wir',        'es' => 'nosotros',   'fr' => 'nous',        'it' => 'noi'],
		'welcome'   => ['de' => 'willkommen',   'es' => 'bienvenido', 'fr' => 'bienvenue',   'it' => 'benvenuto'],
		'what'      => ['de' => 'was',      'es' => 'qué',        'fr' => 'quoi',        'it' => 'che cosa'],
		'when'      => ['de' => 'wann',      'es' => 'cuando',     'fr' => 'quand',       'it' => 'quando'],
		'where'     => ['de' => 'wo',     'es' => 'dónde',      'fr' => 'où',          'it' => 'dove'],
		'which'     => ['de' => 'welcher',     'es' => 'cuál',       'fr' => 'quel',        'it' => 'quale'],
		'who'       => ['de' => 'wer',       'es' => 'quién',      'fr' => 'qui',         'it' => 'chi'],
		'with'      => ['de' => 'mit',      'es' => 'con',        'fr' => 'avec',        'it' => 'con'],
		'work'      => ['de' => 'arbeit',      'es' => 'trabajo',    'fr' => 'travail',     'it' => 'lavoro'],
		'would'     => ['de' => 'würde',     'es' => 'gustaría',   'fr' => 'voudrais',    'it' => 'vorrei'],
		'you'       => ['de' => 'du',       'es' => 'tú',         'fr' => 'tu',          'it' => 'tu'],
		'your'      => ['de' => 'dein',      'es' => 'tu',         'fr' => 'ton',         'it' => 'tuo'],
		'able'      => ['de' => 'fähig',      'es' => 'capaz',      'fr' => 'capable',     'it' => 'capace'],
		'add'       => ['de' => 'hinzufügen',       'es' => 'añadir',     'fr' => 'ajouter',     'it' => 'aggiungere'],
		'air'       => ['de' => 'luft',       'es' => 'aire',       'fr' => 'air',         'it' => 'aria'],
		'always'    => ['de' => 'immer',    'es' => 'siempre',    'fr' => 'toujours',    'it' => 'sempre'],
		'another'   => ['de' => 'ein weiterer',  'es' => 'otro',       'fr' => 'un autre',    'it' => 'un altro'],
		'around'    => ['de' => 'herum',    'es' => 'alrededor',  'fr' => 'autour',      'it' => 'intorno'],
		'ask'       => ['de' => 'fragen',       'es' => 'preguntar',  'fr' => 'demander',    'it' => 'chiedere'],
		'bad'       => ['de' => 'schlecht',       'es' => 'malo',       'fr' => 'mauvais',     'it' => 'cattivo'],
		'become'    => ['de' => 'werden',    'es' => 'convertirse','fr' => 'devenir',     'it' => 'diventare'],
		'begin'     => ['de' => 'beginnen',     'es' => 'comenzar',   'fr' => 'commencer',   'it' => 'iniziare'],
		'best'      => ['de' => 'beste',      'es' => 'mejor',      'fr' => 'meilleur',    'it' => 'migliore'],
		'better'    => ['de' => 'besser',    'es' => 'mejor',      'fr' => 'meilleur',    'it' => 'migliore'],
		'big'       => ['de' => 'groß',       'es' => 'grande',     'fr' => 'grand',       'it' => 'grande'],
		'both'      => ['de' => 'beide',      'es' => 'ambos',      'fr' => 'les deux',    'it' => 'entrambi'],
		'call'      => ['de' => 'anrufen',      'es' => 'llamar',     'fr' => 'appeler',     'it' => 'chiamare'],
		'change'    => ['de' => 'ändern',    'es' => 'cambiar',    'fr' => 'changer',     'it' => 'cambiare'],
		'child'     => ['de' => 'kind',     'es' => 'niño',       'fr' => 'enfant',      'it' => 'bambino'],
		'city'      => ['de' => 'stadt',      'es' => 'ciudad',     'fr' => 'ville',       'it' => 'città'],
		'come'      => ['de' => 'kommen',      'es' => 'venir',      'fr' => 'venir',       'it' => 'venire'],
		'could'     => ['de' => 'könnte',     'es' => 'podría',     'fr' => 'pourrait',    'it' => 'potrebbe'],
		'country'   => ['de' => 'land',   'es' => 'país',       'fr' => 'pays',        'it' => 'paese'],
		'different' => ['de' => 'anders', 'es' => 'diferente',  'fr' => 'différent',   'it' => 'diverso'],
		'during'    => ['de' => 'während',    'es' => 'durante',    'fr' => 'pendant',     'it' => 'durante'],
		'each'      => ['de' => 'jeder',      'es' => 'cada',       'fr' => 'chaque',      'it' => 'ogni'],
		'end'       => ['de' => 'ende',       'es' => 'fin',        'fr' => 'fin',         'it' => 'fine'],
		'enough'    => ['de' => 'genug',    'es' => 'suficiente', 'fr' => 'assez',       'it' => 'abbastanza'],
		'every'     => ['de' => 'jeder',     'es' => 'cada',       'fr' => 'chaque',      'it' => 'ogni'],
		'example'   => ['de' => 'beispiel',   'es' => 'ejemplo',    'fr' => 'exemple',     'it' => 'esempio'],
		'eye'       => ['de' => 'auge',       'es' => 'ojo',        'fr' => 'œil',         'it' => 'occhio'],
		'face'      => ['de' => 'gesicht',      'es' => 'cara',       'fr' => 'visage',      'it' => 'viso'],
		'fact'      => ['de' => 'tatsache',      'es' => 'hecho',      'fr' => 'fait',        'it' => 'fatto'],
		'family'    => ['de' => 'familie',    'es' => 'familia',    'fr' => 'famille',     'it' => 'famiglia'],
		'feel'      => ['de' => 'fühlen',      'es' => 'sentir',     'fr' => 'sentir',      'it' => 'sentire'],
		'find'      => ['de' => 'finden',      'es' => 'encontrar',  'fr' => 'trouver',     'it' => 'trovare'],
		'follow'    => ['de' => 'folgen',    'es' => 'seguir',     'fr' => 'suivre',      'it' => 'seguire'],
		'form'      => ['de' => 'formular',      'es' => 'forma',      'fr' => 'forme',       'it' => 'forma'],
		'give'      => ['de' => 'geben',      'es' => 'dar',        'fr' => 'donner',      'it' => 'dare'],
		'great'     => ['de' => 'großartig',     'es' => 'genial',     'fr' => 'super',       'it' => 'grande'],
		'group'     => ['de' => 'gruppe',     'es' => 'grupo',      'fr' => 'groupe',      'it' => 'gruppo'],
		'hand'      => ['de' => 'hand',      'es' => 'mano',       'fr' => 'main',        'it' => 'mano'],
		'help'      => ['de' => 'hilfe',      'es' => 'ayuda',      'fr' => 'aide',        'it' => 'aiuto'],
		'high'      => ['de' => 'hoch',      'es' => 'alto',       'fr' => 'haut',        'it' => 'alto'],
		'home'      => ['de' => 'zuhause',      'es' => 'casa',       'fr' => 'maison',      'it' => 'casa'],
		'house'     => ['de' => 'haus',     'es' => 'casa',       'fr' => 'maison',      'it' => 'casa'],
		'important' => ['de' => 'wichtig', 'es' => 'importante', 'fr' => 'important',   'it' => 'importante'],
		'into'      => ['de' => 'in',      'es' => 'en',         'fr' => 'dans',        'it' => 'in'],
		'its'       => ['de' => 'sein',       'es' => 'su',         'fr' => 'son',         'it' => 'suo'],
		'just'      => ['de' => 'nur',      'es' => 'solo',       'fr' => 'juste',       'it' => 'solo'],
		'keep'      => ['de' => 'halten',      'es' => 'mantener',   'fr' => 'garder',      'it' => 'tenere'],
		'kind'      => ['de' => 'art',      'es' => 'tipo',       'fr' => 'genre',       'it' => 'tipo'],
		'large'     => ['de' => 'groß',     'es' => 'grande',     'fr' => 'grand',       'it' => 'grande'],
		'later'     => ['de' => 'später',     'es' => 'más tarde',  'fr' => 'plus tard',   'it' => 'più tardi'],
		'leave'     => ['de' => 'verlassen',     'es' => 'dejar',      'fr' => 'laisser',     'it' => 'lasciare'],
		'life'      => ['de' => 'leben',      'es' => 'vida',       'fr' => 'vie',         'it' => 'vita'],
		'little'    => ['de' => 'klein',    'es' => 'pequeño',    'fr' => 'petit',       'it' => 'piccolo'],
		'long'      => ['de' => 'lang',      'es' => 'largo',      'fr' => 'long',        'it' => 'lungo'],
		'man'       => ['de' => 'mann',       'es' => 'hombre',     'fr' => 'homme',       'it' => 'uomo'],
		'may'       => ['de' => 'können',       'es' => 'poder',      'fr' => 'pouvoir',     'it' => 'potere'],
		'mean'      => ['de' => 'bedeuten',      'es' => 'significar', 'fr' => 'signifier',   'it' => 'significare'],
		'might'     => ['de' => 'könnte',     'es' => 'podría',     'fr' => 'pourrait',    'it' => 'potrebbe'],
		'much'      => ['de' => 'viel',      'es' => 'mucho',      'fr' => 'beaucoup',    'it' => 'molto'],
		'name'      => ['de' => 'name',      'es' => 'nombre',     'fr' => 'nom',         'it' => 'nome'],
		'need'      => ['de' => 'brauchen',      'es' => 'necesitar',  'fr' => 'avoir besoin','it' => 'aver bisogno'],
		'never'     => ['de' => 'nie',     'es' => 'nunca',      'fr' => 'jamais',      'it' => 'mai'],
		'next'      => ['de' => 'nächste',      'es' => 'siguiente',  'fr' => 'prochain',    'it' => 'prossimo'],
		'night'     => ['de' => 'nacht',     'es' => 'noche',      'fr' => 'nuit',        'it' => 'notte'],
		'old'       => ['de' => 'alt',       'es' => 'viejo',      'fr' => 'vieux',       'it' => 'vecchio'],
		'only'      => ['de' => 'nur',      'es' => 'solo',       'fr' => 'seulement',   'it' => 'solo'],
		'other'     => ['de' => 'andere',     'es' => 'otro',       'fr' => 'autre',       'it' => 'altro'],
		'over'      => ['de' => 'über',      'es' => 'sobre',      'fr' => 'sur',         'it' => 'sopra'],
		'own'       => ['de' => 'eigen',       'es' => 'propio',     'fr' => 'propre',      'it' => 'proprio'],
		'place'     => ['de' => 'platz',     'es' => 'lugar',      'fr' => 'endroit',     'it' => 'posto'],
		'point'     => ['de' => 'punkt',     'es' => 'punto',      'fr' => 'point',       'it' => 'punto'],
		'put'       => ['de' => 'setzen',       'es' => 'poner',      'fr' => 'mettre',      'it' => 'mettere'],
		'right'     => ['de' => 'richtig',     'es' => 'derecho',    'fr' => 'droit',       'it' => 'diritto'],
		'same'      => ['de' => 'gleich',      'es' => 'mismo',      'fr' => 'même',        'it' => 'stesso'],
		'say'       => ['de' => 'sagen',       'es' => 'decir',      'fr' => 'dire',        'it' => 'dire'],
		'see'       => ['de' => 'sehen',       'es' => 'ver',        'fr' => 'voir',        'it' => 'vedere'],
		'should'    => ['de' => 'sollte',    'es' => 'debería',    'fr' => 'devrait',     'it' => 'dovrebbe'],
		'show'      => ['de' => 'zeigen',      'es' => 'mostrar',    'fr' => 'montrer',     'it' => 'mostrare'],
		'small'     => ['de' => 'klein',     'es' => 'pequeño',    'fr' => 'petit',       'it' => 'piccolo'],
		'so'        => ['de' => 'so',        'es' => 'tan',        'fr' => 'si',          'it' => 'così'],
		'something' => ['de' => 'etwas', 'es' => 'algo',       'fr' => 'quelque chose','it' => 'qualcosa'],
		'still'     => ['de' => 'noch',     'es' => 'todavía',    'fr' => 'encore',      'it' => 'ancora'],
		'take'      => ['de' => 'nehmen',      'es' => 'tomar',      'fr' => 'prendre',     'it' => 'prendere'],
		'tell'      => ['de' => 'erzählen',      'es' => 'decir',      'fr' => 'dire',        'it' => 'dire'],
		'than'      => ['de' => 'als',      'es' => 'que',        'fr' => 'que',         'it' => 'che'],
		'them'      => ['de' => 'ihnen',      'es' => 'ellos',      'fr' => 'eux',         'it' => 'loro'],
		'then'      => ['de' => 'dann',      'es' => 'entonces',   'fr' => 'alors',       'it' => 'allora'],
		'these'     => ['de' => 'diese',     'es' => 'estos',      'fr' => 'ces',         'it' => 'questi'],
		'thing'     => ['de' => 'ding',     'es' => 'cosa',       'fr' => 'chose',       'it' => 'cosa'],
		'think'     => ['de' => 'denken',     'es' => 'pensar',     'fr' => 'penser',      'it' => 'pensare'],
		'those'     => ['de' => 'jene',     'es' => 'esos',       'fr' => 'ceux',        'it' => 'quelli'],
		'through'   => ['de' => 'durch',   'es' => 'a través',   'fr' => 'à travers',   'it' => 'attraverso'],
		'too'       => ['de' => 'auch',       'es' => 'también',    'fr' => 'aussi',       'it' => 'anche'],
		'under'     => ['de' => 'unter',     'es' => 'bajo',       'fr' => 'sous',        'it' => 'sotto'],
		'use'       => ['de' => 'verwenden',       'es' => 'usar',       'fr' => 'utiliser',    'it' => 'usare'],
		'way'       => ['de' => 'weg',       'es' => 'camino',     'fr' => 'chemin',      'it' => 'via'],
		'well'      => ['de' => 'gut',      'es' => 'bien',       'fr' => 'bien',        'it' => 'bene'],
		'while'     => ['de' => 'während',     'es' => 'mientras',   'fr' => 'pendant que', 'it' => 'mentre'],
		'why'       => ['de' => 'warum',       'es' => 'por qué',    'fr' => 'pourquoi',    'it' => 'perché'],
		'will'      => ['de' => 'werden',      'es' => 'futuro',     'fr' => 'futur',       'it' => 'futuro'],
		'world'     => ['de' => 'welt',     'es' => 'mundo',      'fr' => 'monde',       'it' => 'mondo'],
		'write'     => ['de' => 'schreiben',     'es' => 'escribir',   'fr' => 'écrire',      'it' => 'scrivere'],
		'year'      => ['de' => 'jahr',      'es' => 'año',        'fr' => 'an',          'it' => 'anno'],
		'yes'       => ['de' => 'ja',       'es' => 'sí',         'fr' => 'oui',         'it' => 'sì'],
		'already'   => ['de' => 'schon',   'es' => 'ya',         'fr' => 'déjà',        'it' => 'già'],
		'two'       => ['de' => 'zwei',       'es' => 'dos',        'fr' => 'deux',        'it' => 'due'],
		'three'     => ['de' => 'drei',     'es' => 'tres',       'fr' => 'trois',       'it' => 'tre'],
		'four'      => ['de' => 'vier',      'es' => 'cuatro',     'fr' => 'quatre',      'it' => 'quattro'],
		'five'      => ['de' => 'fünf',      'es' => 'cinco',      'fr' => 'cinq',        'it' => 'cinque'],
		'red'       => ['de' => 'rot',       'es' => 'rojo',       'fr' => 'rouge',       'it' => 'rosso'],
		'blue'      => ['de' => 'blau',      'es' => 'azul',       'fr' => 'bleu',        'it' => 'blu'],
		'green'     => ['de' => 'grün',     'es' => 'verde',      'fr' => 'vert',        'it' => 'verde'],
		'white'     => ['de' => 'weiß',     'es' => 'blanco',     'fr' => 'blanc',       'it' => 'bianco'],
		'black'     => ['de' => 'schwarz',     'es' => 'negro',      'fr' => 'noir',        'it' => 'nero'],
		'run'       => ['de' => 'laufen',       'es' => 'correr',     'fr' => 'courir',      'it' => 'correre'],
		'walk'      => ['de' => 'gehen',      'es' => 'caminar',    'fr' => 'marcher',     'it' => 'camminare'],
		'eat'       => ['de' => 'essen',       'es' => 'comer',      'fr' => 'manger',      'it' => 'mangiare'],
		'drink'     => ['de' => 'trinken',     'es' => 'beber',      'fr' => 'boire',       'it' => 'bere'],
		'water'     => ['de' => 'Wasser',     'es' => 'agua',       'fr' => 'eau',         'it' => 'acqua'],
		'food'      => ['de' => 'Essen',      'es' => 'comida',     'fr' => 'nourriture',  'it' => 'cibo'],
		'money'     => ['de' => 'Geld',     'es' => 'dinero',     'fr' => 'argent',      'it' => 'denaro'],
	];

	/**
	 * Per-language substitution patterns for fake morphing of unknown words.
	 * Applied longest-key-first; if nothing matches a vowel ending is appended.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const FAKE_SUBS = [
		'de' => ['tion' => 'zion', 'sion' => 'zion', 'ness' => 'heit', 'ful' => 'voll',
		         'less' => 'los',  'ous'  => 'lich',  'ing'  => 'ung',  'ly'  => 'lich',
		         'ph'   => 'f',    'th'   => 'ss',    'ck'   => 'k'],
		'es' => ['tion' => 'ción', 'sion' => 'sión',  'ness' => 'dad',  'ful' => 'oso',
		         'less' => 'sin',  'ous'  => 'oso',   'ing'  => 'ando', 'ly'  => 'mente',
		         'ph'   => 'f',    'th'   => 't',     'ck'   => 'c'],
		'fr' => ['tion' => 'tion', 'sion' => 'sion',  'ness' => 'ité',  'ful' => 'eux',
		         'less' => 'sans', 'ous'  => 'eux',   'ing'  => 'ant',  'ly'  => 'ment',
		         'ph'   => 'f',    'th'   => 't',     'ck'   => 'que'],
		'it' => ['tion' => 'zione','sion' => 'sione', 'ness' => 'ità',  'ful' => 'oso',
		         'less' => 'senza','ous'  => 'oso',   'ing'  => 'ando', 'ly'  => 'mente',
		         'ph'   => 'f',    'th'   => 't',     'ck'   => 'c'],
		'en' => ['ung'  => 'tion', 'heit' => 'ness',  'lich' => 'ly',   'voll' => 'ful',
		         'sch'  => 'sh',   'tz'   => 'ts',    'ei'   => 'ay',   'ie'   => 'ee'],
	];

	/** @var array<string, string>|null */
	private static ?array $lookup = null;

	public function supports(string $api): bool
	{
		return self::API === $api;
	}

	/**
	 * @param array<string, mixed> $modelData
	 * @param array<string, mixed> $requestOptions
	 */
	public function translate(string $text, ?string $srcLang, string $targetLang, string $format, array $modelData, array $requestOptions = []): VTransProviderResult
	{
		$format = strtolower(trim($format));
		if (!in_array($format, ['text', 'html'], true)) {
			throw new rex_exception('Fake local provider only supports text and html format.');
		}

		$target = $this->normalizeLang($targetLang);
		if (!isset(self::SUPPORTED_LANGS[$target])) {
			throw new rex_exception('Fake local provider does not support target language: ' . $targetLang);
		}

		$source = null !== $srcLang && '' !== trim($srcLang)
			? $this->normalizeLang($srcLang)
			: 'de';
		if (!isset(self::SUPPORTED_LANGS[$source])) {
			$source = 'de';
		}

		$translated = 'html' === $format
			? $this->translateHtml($text, $source, $target)
			: $this->translatePlainText($text, $source, $target);

		return new VTransProviderResult($translated, [
			'provider' => 'fake-local',
			'api' => self::API,
			'model' => $this->normalizeString($modelData['key'] ?? null),
			'source_language' => $source,
			'target_language' => $target,
			'format' => $format,
			'simulated' => true,
		]);
	}

	/** @param array<string, mixed> $modelData @return array<string, mixed> */
	public function getUsage(array $modelData): array
	{
		return [
			'provider' => 'fake-local',
			'model' => $this->normalizeString($modelData['key'] ?? null),
			'api' => self::API,
			'usage_supported' => false,
			'character' => null,
		];
	}

	public function getAvailableSourceLanguages(): array
	{
		return [
			'de' => 'German (DE)',
			'en' => 'English (EN)',
			'es' => 'Spanish (ES)',
			'fr' => 'French (FR)',
			'it' => 'Italian (IT)',
		];
	}

	public function getAvailableTargetLanguages(): array
	{
		return $this->getAvailableSourceLanguages();
	}

	public function getDefaultTargetLanguage(): string
	{
		return 'en';
	}

	private function normalizeString(mixed $value): string
	{
		return is_string($value) ? $value : '';
	}

	private function translateHtml(string $html, string $source, string $target): string
	{
		$dom = new DOMDocument('1.0', 'UTF-8');
		$wrappedHtml = '<div id="vtrans-fake-root">' . $html . '</div>';

		$prev = libxml_use_internal_errors(true);
		$loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if (false === $loaded) {
			return $html;
		}

		$root = null;
		foreach ($dom->getElementsByTagName('div') as $element) {
			if ('vtrans-fake-root' === $element->getAttribute('id')) {
				$root = $element;
				break;
			}
		}

		if (null === $root) {
			return $html;
		}

		$this->walkHtmlNodes($root, false, $source, $target);

		$out = '';
		for ($i = 0; $i < $root->childNodes->length; $i++) {
			$child = $root->childNodes->item($i);
			if ($child instanceof DOMNode) {
				$out .= (string) $dom->saveHTML($child);
			}
		}

		return $out;
	}

	private function walkHtmlNodes(DOMNode $node, bool $skip, string $source, string $target): void
	{
		$currentSkip = $skip;
		if ($node instanceof DOMElement) {
			$tag = strtolower($node->tagName);
			if (in_array($tag, ['script', 'style', 'code', 'svg'], true)) {
				$currentSkip = true;
			}

			if ($node->hasAttribute('data-vtrans-notranslate')) {
				$currentSkip = true;
			}

			if ('no' === strtolower(trim((string) $node->getAttribute('translate')))) {
				$currentSkip = true;
			}

			$class = (string) $node->getAttribute('class');
			if (preg_match('/(^|\s)notranslate(\s|$)/i', $class)) {
				$currentSkip = true;
			}
		}

		if ($node instanceof DOMText && !$currentSkip) {
			$node->nodeValue = $this->translatePlainText((string) $node->nodeValue, $source, $target);
			return;
		}

		for ($i = 0; $i < $node->childNodes->length; $i++) {
			$child = $node->childNodes->item($i);
			if ($child instanceof DOMNode) {
				$this->walkHtmlNodes($child, $currentSkip, $source, $target);
			}
		}
	}

	private function translatePlainText(string $text, string $source, string $target): string
	{
		if ('' === trim($text)) {
			return $text;
		}

		// Keep obvious technical strings unchanged.
		if (preg_match('/[@]|https?:\/\/|www\.|\+?[0-9][0-9\s\-\/]+/', $text)) {
			return $text;
		}

		return preg_replace_callback('/[\p{L}][\p{L}\p{Mn}\-]*/u', function (array $m) use ($source, $target): string {
			$word = (string) $m[0];
			$translated = $this->translateWord($word, $source, $target);

			return $translated;
		}, $text) ?? $text;
	}

	private function translateWord(string $word, string $source, string $target): string
	{
		$lookup = self::getLookup();
		$lowerWord = $this->toLower($word);
		if (!isset($lookup[$source . '|' . $lowerWord])) {
			if ($source === $target || mb_strlen($word, 'UTF-8') < 3) {
				return $word;
			}
			return $this->preserveWordCase($word, $this->fakeTranslate($lowerWord, $target));
		}

		$concept = $lookup[$source . '|' . $lowerWord];
		$targetWord = 'en' === $target ? $concept : (self::LEXICON[$concept][$target] ?? null);
		if (null === $targetWord || '' === trim($targetWord)) {
			return $word;
		}

		return $this->preserveWordCase($word, $targetWord);
	}

	private function fakeTranslate(string $lowerWord, string $target): string
	{
		$subs = self::FAKE_SUBS[$target] ?? null;
		if (!is_array($subs)) {
			return $lowerWord;
		}

		// Apply substitutions longest-key-first to avoid partial clobbering
		$sorted = $subs;
		uksort($sorted, static fn(string $a, string $b): int => mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8'));
		foreach ($sorted as $find => $replace) {
			if (str_contains($lowerWord, $find)) {
				return str_replace($find, $replace, $lowerWord);
			}
		}

		// No pattern matched — append a language-typical ending to consonant-final words
		/** @var array<string, list<string>> $endings */
		static $endings = [
			'de' => ['en', 'er', 'el', 'ung'],
			'es' => ['o', 'a', 'ar', 'al'],
			'fr' => ['e', 'ée', 'er', 'eur'],
			'it' => ['o', 'a', 'are', 'ore'],
			'en' => ['ing', 'ed', 'er', 'al'],
		];
		$langEndings = $endings[$target] ?? [];
		if ([] !== $langEndings) {
			$lastChar = mb_substr($lowerWord, -1, 1, 'UTF-8');
			if (!in_array($lastChar, ['a', 'e', 'i', 'o', 'u'], true)) {
				$idx = mb_strlen($lowerWord, 'UTF-8') % count($langEndings);
				/** @var list<string> $langEndings */
				return $lowerWord . $langEndings[$idx];
			}
		}

		return $lowerWord;
	}

	/** @return array<string, string> */
	private static function getLookup(): array
	{
		if (null !== self::$lookup) {
			return self::$lookup;
		}

		$lookup = [];
		foreach (self::LEXICON as $concept => $entries) {
			$lookup['en|' . $concept] = $concept;
			foreach ($entries as $lang => $value) {
				$value = trim((string) $value);
				if ('' === $value) {
					continue;
				}
				$lookup[$lang . '|' . self::lowerStatic($value)] = $concept;
			}
		}

		self::$lookup = $lookup;
		return self::$lookup;
	}

	private static function lowerStatic(string $value): string
	{
		if (function_exists('mb_strtolower')) {
			return mb_strtolower($value, 'UTF-8');
		}

		return strtolower($value);
	}

	private function preserveWordCase(string $sourceWord, string $translatedWord): string
	{
		if ('' === $sourceWord) {
			return $translatedWord;
		}

		if ($this->toUpper($sourceWord) === $sourceWord) {
			return $this->toUpper($translatedWord);
		}

		$first = $this->substr($sourceWord, 0, 1);
		$rest = $this->substr($sourceWord, 1);
		if ($this->toUpper($first) === $first && $this->toLower($rest) === $rest) {
			return $this->toUpper($this->substr($translatedWord, 0, 1)) . $this->substr($translatedWord, 1);
		}

		return $translatedWord;
	}

	private function normalizeLang(string $lang): string
	{
		$lang = strtolower(str_replace('_', '-', trim($lang)));
		if ('' === $lang) {
			return '';
		}

		$parts = explode('-', $lang, 2);
		return $parts[0];
	}

	private function toLower(string $value): string
	{
		if (function_exists('mb_strtolower')) {
			return mb_strtolower($value, 'UTF-8');
		}

		return strtolower($value);
	}

	private function toUpper(string $value): string
	{
		if (function_exists('mb_strtoupper')) {
			return mb_strtoupper($value, 'UTF-8');
		}

		return strtoupper($value);
	}

	private function substr(string $value, int $start, ?int $length = null): string
	{
		if (function_exists('mb_substr')) {
			return mb_substr($value, $start, $length, 'UTF-8');
		}

		return null === $length
			? substr($value, $start)
			: substr($value, $start, $length);
	}

	/** @return array<string, mixed> */
	public function getLastDebugData(): array
	{
		return [];
	}

	public function getProviderLabel(): string
	{
		return 'Fake Local (Dev)';
	}

	/** @return list<string> */
	public function getApiIdentifiers(): array
	{
		return ['fake-local-v1'];
	}

	public function getConfigFields(): array
	{
		return [];
	}

	/** @param array<string, mixed> $values @return array<string, string> */
	public function validateConfig(array $values): array
	{
		return [];
	}
}
