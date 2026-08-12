<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Mcp\Tests\Application\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\CreateArticleMessage;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Domain\Model\Page;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class ArticleFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        #[Autowire(service: 'sulu_message_bus')]
        private readonly MessageBusInterface $messageBus,
        private readonly BlockIdGeneratorInterface $blockIdGenerator,
    ) {
    }

    /**
     * Recursively inject a generated _id into every block-shaped array in $data.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function injectBlockIds(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = $this->injectBlockIds($value);
            }
        }

        if (isset($data['type']) && \is_string($data['type']) && !isset($data['_id'])) {
            $data['_id'] = $this->blockIdGenerator->generateId();
        }

        return $data;
    }

    public function getDependencies(): array
    {
        return [PageFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Page $blogPage */
        $blogPage = $this->getReference(PageFixtures::BLOG_PAGE_REFERENCE, Page::class);
        $blogPageUuid = $blogPage->getUuid();

        /** @var Page $musicPage */
        $musicPage = $this->getReference(PageFixtures::MUSIC_PAGE_REFERENCE, Page::class);
        $musicPageUuid = $musicPage->getUuid();

        foreach ($this->getArticlesData($blogPageUuid) as $articleData) {
            $this->createAndPublishArticle($articleData);
        }

        foreach ($this->getMusicArtistArticles($musicPageUuid) as $articleData) {
            $this->createAndPublishArticle($articleData);
        }

        foreach ($this->getAdditionalMusicArticles($musicPageUuid) as $articleData) {
            $this->createAndPublishArticle($articleData);
        }

        foreach ($this->getAdditionalBlogArticles($blogPageUuid) as $articleData) {
            $this->createAndPublishArticle($articleData);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createAndPublishArticle(array $data): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->injectBlockIds($data);
        $envelope = $this->messageBus->dispatch(
            new Envelope(
                new CreateArticleMessage($data),
                [new EnableFlushStamp()],
            ),
        );

        /** @var HandledStamp[] $handledStamps */
        $handledStamps = $envelope->all(HandledStamp::class);

        /** @var ArticleInterface $article */
        $article = $handledStamps[0]->getResult();

        $this->messageBus->dispatch(
            new Envelope(
                new ApplyWorkflowTransitionArticleMessage(
                    identifier: ['uuid' => $article->getUuid()],
                    locale: 'en',
                    transitionName: WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
                ),
                [new EnableFlushStamp()],
            ),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getAdditionalMusicArticles(string $musicPageUuid): array
    {
        $artists = [
            ['The Beatles', 'beatles', 'The Beatles — John Lennon, Paul McCartney, George Harrison, and Ringo Starr — are the best-selling music act in history. Their 1963–1970 run compressed more stylistic evolution than most genres achieve in decades.',
                'From Merseybeat to Abbey Road', 'Starting as a skiffle-rooted beat group in Liverpool, the Beatles absorbed American rock and roll, Motown soul, folk, psychedelia, Indian classical music, and avant-garde tape experiments, synthesising them into a body of work that still sounds inexhaustible.', 'Music is everybody\'s possession. It\'s only publishers who think that people own it.', 'John Lennon',
                'Cultural Impact', 'The British Invasion they led transformed popular music permanently. Albums like <em>Revolver</em>, <em>Sgt. Pepper\'s Lonely Hearts Club Band</em>, and <em>Abbey Road</em> remain reference points for every subsequent generation of musicians.'],
            ['Led Zeppelin', 'led-zeppelin', 'Led Zeppelin — Jimmy Page, Robert Plant, John Paul Jones, and John Bonham — defined heavy rock and invented many of the tropes that hard rock and metal would spend the next fifty years refining.',
                'Blues, Folk, and Enormity', 'Drawing on American delta blues, British folk, Eastern scales, and the hard-edged rock of their late-1960s contemporaries, Led Zeppelin built a sound of almost geological scale. Bonham\'s drumming in particular remains a benchmark for power and feel.', 'I believe every human has a finite number of heartbeats. I don\'t intend to waste any of mine.', 'Neil Armstrong (a frequent Zeppelin tour companion)',
                'Catalogue Highlights', '<em>Led Zeppelin IV</em> (1971), containing Stairway to Heaven, Black Dog, and When the Levee Breaks, is one of the best-selling albums in history. <em>Physical Graffiti</em> (1975) is their most ambitious statement.'],
            ['Bob Dylan', 'bob-dylan', 'Bob Dylan (born Robert Allen Zimmerman, 1941) is the most celebrated songwriter in the history of popular music, awarded the Nobel Prize in Literature in 2016 for having created new poetic expressions within the great American song tradition.',
                'Voice of a Generation', 'Arriving in New York in 1961, Dylan absorbed Woody Guthrie\'s protest folk tradition and rapidly transcended it. <em>Blowin\' in the Wind</em> and <em>The Times They Are a-Changin\'</em> became anthems of the civil rights era.', 'A man is a success if he gets up in the morning and gets to bed at night, and in between he does what he wants to do.', 'Bob Dylan',
                'Electric Turn and Later Career', 'His 1965 Newport Folk Festival electric performance remains one of the most debated moments in rock history. The subsequent <em>Highway 61 Revisited</em> and <em>Blonde on Blonde</em> redefined what a pop song could contain. His reinventions have continued for sixty years.'],
            ['Aretha Franklin', 'aretha-franklin', 'Aretha Franklin (1942–2018), the Queen of Soul, possessed arguably the greatest vocal instrument in the history of recorded music — an instrument she wielded with a combination of church-trained technique, emotional abandon, and unerring musicality.',
                'Gospel Roots and Atlantic Years', 'Raised in Detroit by her minister father C.L. Franklin, Aretha grew up singing gospel. Her move to Atlantic Records in 1967 unlocked her greatest period: <em>I Never Loved a Man the Way I Love You</em>, <em>Respect</em>, <em>Chain of Fools</em>, and <em>Natural Woman</em> arrived within eighteen months.', 'Being the Queen is not all about singing, and being a diva is not all about singing. It has much to do with your service to people.', 'Aretha Franklin',
                'Civil Rights and Legacy', 'Aretha was close to Martin Luther King Jr. and sang at his funeral. She performed at three presidential inaugurations. Sixty years after her debut, her gospel album <em>Amazing Grace</em> (1972) remains the best-selling live gospel album in history.'],
            ['Stevie Wonder', 'stevie-wonder', 'Stevie Wonder (born 1950) signed with Motown at age eleven and proceeded to release some of the most musically sophisticated and socially engaged popular music of the twentieth century.',
                'From Child Prodigy to Auteur', 'His early Motown hits were remarkable for their age but his 1972–1976 run — <em>Music of My Mind</em>, <em>Talking Book</em>, <em>Innervisions</em>, <em>Fulfillingness\' First Finale</em>, and <em>Songs in the Key of Life</em> — was one of the most sustained creative peaks in popular music history.', 'Just because a man lacks the use of his eyes doesn\'t mean he lacks vision.', 'Stevie Wonder',
                'Mastery Across Genres', 'Wonder played virtually every instrument on his records, weaving funk, soul, jazz, gospel, and orchestral pop into a uniquely personal sound. <em>Songs in the Key of Life</em> is widely cited as the greatest double album ever made.'],
            ['Prince', 'prince', 'Prince Rogers Nelson (1958–2016) was a Minneapolis-born multi-instrumentalist, singer, songwriter, and producer who made funk, soul, rock, pop, and R&B feel like expressions of a single boundless musical personality.',
                'Purple Rain and Beyond', '<em>Purple Rain</em> (1984) — the film and album — made Prince a global superstar. But his finest work may be the records he made on either side of that commercial peak: <em>Dirty Mind</em>, <em>1999</em>, <em>Around the World in a Day</em>, <em>Sign "☮" the Times</em>.', 'Despite everything, no one can dictate who you are to other people.', 'Prince',
                'Prolific and Uncompromising', 'Prince owned his masters before it was fashionable and fought publicly with Warner Bros. over artistic control. He is estimated to have recorded hundreds of unreleased albums in his vault at Paisley Park. After his death, a warehouse of music began to emerge.'],
            ['Michael Jackson', 'michael-jackson', 'Michael Jackson (1958–2009) was the King of Pop — the best-selling solo music artist of all time, with <em>Thriller</em> (1982) remaining the best-selling album in history.',
                'From Jackson 5 to Thriller', 'Jackson\'s childhood in Gary, Indiana, and his early years with the Jackson 5 gave him a performing foundation unlike any of his contemporaries. His partnership with producer Quincy Jones yielded <em>Off the Wall</em> (1979), <em>Thriller</em> (1982), and <em>Bad</em> (1987) — three consecutive records of towering commercial and artistic ambition.', 'In a world filled with hate, we must still dare to hope. In a world filled with anger, we must still dare to comfort.', 'Michael Jackson',
                'Dance and Visual Innovation', 'Jackson\'s integration of music video as an art form — <em>Billie Jean</em>, <em>Thriller</em>, <em>Black or White</em> — transformed the medium. His dance innovations, including the moonwalk, remain touchstones of popular culture.'],
            ['Jimi Hendrix', 'jimi-hendrix', 'Jimi Hendrix (1942–1970) compressed a lifetime of musical development into four years of recording. No guitarist before or since has reshaped what the instrument can do with such ferocity and imagination.',
                'Arrival and the Experience', 'A Seattle-born Black guitarist playing the chitlin circuit, Hendrix was spotted in New York by Chas Chandler of The Animals and moved to London in 1966. Within a year the Jimi Hendrix Experience had released <em>Are You Experienced</em> — a record that still sounds like a transmission from a parallel universe.', 'I\'m the one who has to die when it\'s time for me to die, so let me live my life the way I want to.', 'Jimi Hendrix',
                'Woodstock and Legacy', 'His performance at Woodstock — a forty-minute set ending with a deconstruction of the Star-Spangled Banner — is among the most analyzed performances in rock history. He died at twenty-seven, leaving a catalogue that continues to influence every electric guitarist.'],
            ['Johnny Cash', 'johnny-cash', 'Johnny Cash (1932–2003) was the Man in Black — an Arkansas-born country singer whose work spanned rockabilly, folk, gospel, and rock and roll, and whose late-career American Recordings series redefined what a veteran artist could achieve.',
                'Sun Records and the Nashville Sound', 'Cash signed with Sun Records in Memphis in 1955, where his trio — Cash, Luther Perkins, and Marshall Grant — developed a stripped-down boom-chicka-boom sound that cut through the ornate Nashville production of the era. <em>I Walk the Line</em> and <em>Folsom Prison Blues</em> were immediate classics.', 'You\'ve got to know your limitations. I don\'t know what your limitations are. I found out what mine were when I was twelve.', 'Johnny Cash',
                'The American Recordings', 'Signed by producer Rick Rubin in 1993 when Nashville had lost interest, Cash recorded a series of sparse, emotionally devastating albums. His cover of Nine Inch Nails\'s <em>Hurt</em>, released months before his death, became one of the most celebrated music videos ever made.'],
            ['Chuck Berry', 'chuck-berry', 'Chuck Berry (1926–2017) invented rock and roll\'s vocabulary — the guitar riffs, the showmanship, the subject matter (cars, girls, school, Saturday night) — and every rock musician who followed owes him a direct debt.',
                'Chess Records and the Foundation', 'Recording for Chess Records in Chicago from 1955, Berry created a string of records that codified the genre: <em>Maybellene</em>, <em>Roll Over Beethoven</em>, <em>Johnny B. Goode</em>, <em>Rock and Roll Music</em>. The Beatles covered him, the Rolling Stones covered him, the Beach Boys borrowed his melodies.', 'If you tried to give rock and roll another name, you might call it Chuck Berry.', 'John Lennon',
                'The Riff That Started Everything', 'The opening guitar figure of <em>Johnny B. Goode</em> may be the most imitated riff in history. The song was included on the Voyager Golden Record, sent into interstellar space in 1977 as a representative sample of human music.'],
            ['Ray Charles', 'ray-charles', 'Ray Charles (1930–2004) fused gospel and R&B to create soul music, then went further — recording country, jazz, pop standards, and orchestral arrangements with equal command.',
                'The Genius of Soul', 'Blind from age seven, Charles developed perfect pitch and an encyclopedic ear. His mid-1950s Atlantic recordings — <em>I Got a Woman</em>, <em>Hallelujah I Love Her So</em>, <em>What\'d I Say</em> — created a new genre by transplanting gospel fervor into secular R&B.', 'I was born with music inside me. Music was one of my parts. Like my ribs, my kidneys, my liver, my heart.', 'Ray Charles',
                'Country and Popular Success', 'His 1962 country album <em>Modern Sounds in Country and Western Music</em> was the year\'s best-selling record — a Black jazz musician reinventing white country music for a mass audience at the height of the civil rights movement.'],
            ['James Brown', 'james-brown', 'James Brown (1933–2006) — the Godfather of Soul, the hardest working man in show business — essentially invented funk, and through it influenced hip-hop, dance music, and afrobeat in ways that are still unfolding.',
                'From Prison to the Apollo', 'Brown grew up in extreme poverty in Georgia and served prison time as a teenager. His 1963 album <em>Live at the Apollo</em> — recorded without the label\'s permission — was a watershed: it proved a Black artist\'s live album could sell to white audiences in enormous numbers.', 'Hair is the first thing. And teeth the second. Hair and teeth. A man got those two things he\'s got it all.', 'James Brown',
                'The Rhythm Machine', 'From the late 1960s, Brown\'s recordings stripped melody to the bone and made rhythm everything. <em>Cold Sweat</em> (1967) is often cited as the moment funk was born. His rhythmic innovations became the most sampled body of work in hip-hop history.'],
            ['Marvin Gaye', 'marvin-gaye', 'Marvin Gaye (1939–1984) transformed Motown from a singles factory into an album-oriented art form and created <em>What\'s Going On</em> (1971), one of the greatest and most prescient political albums ever made.',
                'What\'s Going On', 'Recording for Motown against Berry Gordy\'s wishes — Gordy called the material uncommercial — Gaye produced a suite of songs about the Vietnam War, police brutality, poverty, and ecological destruction that felt like they had arrived from the future. The album sold two million copies in its first year.', 'If you cannot find peace within yourself, you will never find it anywhere else.', 'Marvin Gaye',
                'Sexual Healing and Late Career', 'After leaving Motown and spending years in exile in Europe, Gaye returned with <em>Sexual Healing</em> (1982), his biggest commercial hit. He was shot and killed by his father the day before his forty-fifth birthday.'],
            ['The Rolling Stones', 'rolling-stones', 'The Rolling Stones — Mick Jagger, Keith Richards, Charlie Watts, Ronnie Wood (and originally Brian Jones and Bill Wyman) — have been performing for over sixty years, making them the longest-running major act in rock history.',
                'The Anti-Beatles', 'Where the Beatles represented mop-topped accessibility, the Stones projected a dangerous sexuality rooted in American blues. Richards\'s open-G guitar tuning and Jagger\'s strutting physicality created a template for rock performance that has never been bettered.', 'Lose your dreams and you might lose your mind.', 'Mick Jagger',
                'Exile on Main St.', '<em>Exile on Main St.</em> (1972), recorded in the basement of a villa in the south of France, is widely considered their masterpiece — a chaotic, joyful, and deeply funky sprawl through country, blues, gospel, and rock.'],
            ['Bruce Springsteen', 'bruce-springsteen', 'Bruce Springsteen (born 1949) — the Boss — has spent fifty years documenting working-class American life with a novelist\'s eye and a preacher\'s voice.',
                'Born to Run and Nebraska', 'Born in Asbury Park, New Jersey, Springsteen signed to Columbia Records at twenty-two after Jon Landau wrote that he had seen rock and roll\'s future. <em>Born to Run</em> (1975) delivered on that promise. <em>Nebraska</em> (1982), recorded alone on a four-track cassette, is his most harrowing work.', 'Talk about a dream, try to make it real.', 'Bruce Springsteen',
                'Born in the USA', '<em>Born in the USA</em> (1984) produced seven top-ten singles and sold thirty million copies, though its anthem was frequently misread as patriotic rather than the bitter lament it actually is. Springsteen has spent forty years gently correcting this interpretation.'],
            ['Tom Waits', 'tom-waits', 'Tom Waits (born 1949) is an American songwriter, actor, and composer whose music — part blues, part jazz, part vaudeville, part found-sound avant-garde — occupies territory no one else has ever claimed.',
                'Skid Row Balladeer', 'His early Asylum Records albums (<em>Closing Time</em>, <em>Small Change</em>, <em>Blue Valentine</em>) cast him as a beatnik barfly crooning to the night. His gravelly voice, piano, and sardonic worldview made him a cult figure.', 'I\'d rather have a free bottle in front of me than a prefrontal lobotomy.', 'Tom Waits',
                'Swordfishtrombones and After', 'His 1983 move to Island Records produced <em>Swordfishtrombones</em>, which replaced nightclub jazz with clang and clatter, orchestral strangeness, and a mythology of American grotesques. <em>Rain Dogs</em> and <em>Franks Wild Years</em> completed a trilogy that remains one of rock\'s strangest achievements.'],
            ['Radiohead', 'radiohead', 'Radiohead are an Oxford quintet — Thom Yorke, Jonny Greenwood, Colin Greenwood, Ed O\'Brien, and Philip Selway — who progressed from alt-rock to electronic minimalism over a thirty-year career that has consistently redefined what a rock band can sound like.',
                'OK Computer and Kid A', '<em>OK Computer</em> (1997) distilled millennial anxiety into an album-length meditation on alienation and technology. <em>Kid A</em> (2000) abandoned guitars almost entirely, absorbing Krautrock, jazz, and electronic music into something entirely new.', 'We\'re a rock band; we\'re not trying to be post-rock.', 'Thom Yorke',
                'In Rainbows and Sustainability', '<em>In Rainbows</em> (2007), released as a pay-what-you-want download, was a landmark experiment in music distribution that prompted the industry to reconsider its entire model. Their commitment to artistic reinvention over commercial formula remains rare at their level.'],
            ['Nirvana', 'nirvana', 'Nirvana — Kurt Cobain, Krist Novoselic, and Dave Grohl — catalysed the mainstream breakthrough of alternative rock in 1991 and made grunge a global phenomenon, before Cobain\'s death at twenty-seven ended the band abruptly.',
                'Nevermind', '<em>Nevermind</em> (1991) knocked Michael Jackson off the top of the Billboard charts. Produced by Butch Vig, it wrapped pop hooks in distorted guitars and Cobain\'s wounded howl. <em>Smells Like Teen Spirit</em> became a generational anthem almost overnight.', 'I\'d rather be hated for who I am than loved for who I\'m not.', 'Kurt Cobain',
                'In Utero and Legacy', '<em>In Utero</em> (1993), produced by Steve Albini as a deliberate anti-commercial statement, was Cobain\'s answer to <em>Nevermind</em>\'s unexpected mainstream embrace. Cobain died in April 1994; Grohl formed Foo Fighters; Novoselic moved into politics.'],
            ['Leonard Cohen', 'leonard-cohen', 'Leonard Cohen (1934–2016) was a Canadian poet and novelist who turned to songwriting in his thirties and produced one of the most distinctive and emotionally profound catalogues in popular music.',
                'Suzanne and the Early Years', 'Cohen\'s debut album (1967) introduced a hypnotic fingerpicking style and a gift for lyric that felt less like pop songwriting than literary composition. <em>Suzanne</em>, <em>Sisters of Mercy</em>, and <em>Bird on the Wire</em> established him as a cult figure.', 'Poetry is just the evidence of life. If your life is burning well, poetry is just the ash.', 'Leonard Cohen',
                'Hallelujah and Late Renaissance', '<em>Hallelujah</em>, written in the 1980s and recorded definitively by John Cale and Jeff Buckley, has become one of the most covered songs in history. Cohen\'s final album, <em>You Want It Darker</em>, released three weeks before his death at eighty-two, was widely received as a masterwork farewell.'],
            ['Nick Cave', 'nick-cave', 'Nick Cave (born 1957) is an Australian singer, author, screenwriter, and composer whose work — with the Bad Seeds and alone — has explored violence, faith, grief, and love with a literary ambition matched by few of his contemporaries.',
                'From the Birthday Party to the Bad Seeds', 'Cave\'s post-punk band the Birthday Party was a snarling, confrontational force that relocated from Melbourne to London and Berlin in the early 1980s. The Bad Seeds, formed in 1983, gave him a more expansive canvas.', 'I\'m always interested in the process of creation rather than the product.', 'Nick Cave',
                'Skeleton Tree and Grief', 'After the death of his teenage son Arthur in 2015, Cave made <em>Skeleton Tree</em> — a record of devastating grief that has become one of the most discussed albums of the decade. The accompanying documentary <em>One More Time with Feeling</em> is essential viewing.'],
            ['Kraftwerk', 'kraftwerk', 'Kraftwerk — Ralf Hütter, Florian Schneider, and collaborators — created electronic music\'s foundational vocabulary in Düsseldorf in the 1970s and directly inspired hip-hop, techno, house, and synth-pop.',
                'Autobahn and the Machine', '<em>Autobahn</em> (1974) used synthesizers, vocoders, and drum machines to create a twenty-two-minute piece mimicking a motorway drive. It was a hit on both sides of the Atlantic, which surprised everyone including the band.', 'We are playing the machines, the machines are playing us.', 'Ralf Hütter',
                'Trans-Europe Express and The Man-Machine', '<em>Trans-Europe Express</em> (1977) and <em>The Man-Machine</em> (1978) completed the aesthetic: robotic beats, melodic synth lines, and lyrics about technology and modernity. Afrika Bambaataa\'s <em>Planet Rock</em> (1982) sampled Kraftwerk and ignited electro, hip-hop\'s first electronic offshoot.'],
            ['Daft Punk', 'daft-punk', 'Daft Punk — Thomas Bangalter and Guy-Manuel de Homem-Christo — were the French robot duo who brought house music to global mainstream audiences and made electronic dance music artistically legitimate.',
                'Homework and Discovery', '<em>Homework</em> (1997) introduced a harder, filtered-funk take on house to listeners who had never heard the Chicago original. <em>Discovery</em> (2001) went further, embracing pop melody, disco samples, and a nostalgic warmth that prefigured the nu-disco movement.', 'We\'re not rock stars. We\'re more like scientists or chefs. We create things in a laboratory.', 'Thomas Bangalter',
                'Random Access Memories', '<em>Random Access Memories</em> (2013), recorded with live musicians in a conscious rejection of digital production orthodoxy, won five Grammy Awards including Album of the Year. They dissolved the project in 2021 with characteristic mystery.'],
            ['Massive Attack', 'massive-attack', 'Massive Attack — Robert Del Naja and Grant Marshall, from Bristol — created trip-hop and pioneered a form of electronic music so emotionally weighted it felt like weather.',
                'Blue Lines and Mezzanine', '<em>Blue Lines</em> (1991) introduced trip-hop\'s template: hip-hop beats, dub bass, soul samples, and cinematic atmosphere. <em>Mezzanine</em> (1998) is darker, more paranoid, and arguably their finest work — built on guitar loops, menacing bass, and the voices of Cocteau Twins\'s Elizabeth Fraser.', 'Bristol gave us a chip on our shoulder. That chip is the album.', 'Robert Del Naja',
                'Film and Soundtrack Work', 'Massive Attack\'s score for the television series <em>Killing Eve</em> and their longstanding collaboration with director Adam Curtis brought their aesthetic to new audiences. Their live shows — elaborate multimedia installations — remain benchmarks for production ambition.'],
            ['Aphex Twin', 'aphex-twin', 'Richard D. James, performing as Aphex Twin, is the most singular and technically advanced figure in electronic music — capable of writing music of shattering violence and music of delicate pastoral beauty, sometimes in the same track.',
                'Selected Ambient Works', '<em>Selected Ambient Works Volume II</em> (1994) is ambient music\'s most extreme statement — eighty minutes of near-silence and barely-there melody that requires patient, attentive listening to reveal itself. It was unlike anything released before it.', 'I was trying to make music that would be so good that no one could hear it.', 'Richard D. James',
                'Drukqs and Come to Daddy', 'The video for <em>Come to Daddy</em> (1997) — directed by Chris Cunningham — is one of the most disturbing music videos ever made. <em>Drukqs</em> (2001) juxtaposed prepared piano pieces with breakbeat brutalism in a double album of bewildering range.'],
            ['John Coltrane', 'john-coltrane', 'John Coltrane (1926–1967) was a saxophonist and composer who pushed jazz further into abstraction than any of his contemporaries, producing work of extraordinary technical mastery and spiritual intensity.',
                'A Love Supreme', '<em>A Love Supreme</em> (1965) is a four-part suite dedicated to God — a record of such emotional and spiritual force that it transcends genre. It is jazz\'s closest equivalent to religious music.', 'My music is the spiritual expression of what I am — my faith, my knowledge, my being.', 'John Coltrane',
                'Free Jazz and Ascension', 'His late-period recordings — <em>Ascension</em>, <em>Meditations</em>, <em>Om</em> — pushed into free jazz territory that lost some listeners but opened a space that later musicians spent decades exploring. He died of liver cancer at forty.'],
            ['Thelonious Monk', 'thelonious-monk', 'Thelonious Monk (1917–1982) was the most harmonically idiosyncratic pianist in jazz history — a composer whose tunes are instantly recognizable but remain technically demanding seventy years after he wrote them.',
                'Bebop and Beyond', 'Monk was central to the creation of bebop at Minton\'s Playhouse in Harlem in the early 1940s, though his angular, dissonant style was so personal that even fellow beboppers found it strange. His compositions — <em>Round Midnight</em>, <em>Straight, No Chaser</em>, <em>Blue Monk</em> — are jazz standards.', 'The piano ain\'t got no wrong notes.', 'Thelonious Monk',
                'Influence and Isolation', 'Monk spent years without a cabaret licence — revoked following an arrest — unable to perform in New York clubs. When he returned, he was recognized as a visionary. He stopped performing in 1976 and spent his last six years in silence.'],
            ['Ella Fitzgerald', 'ella-fitzgerald', 'Ella Fitzgerald (1917–1996), the First Lady of Song, possessed a voice of such precision, range, warmth, and improvisational invention that she is the consensus choice for the greatest jazz vocalist in history.',
                'The Songbook Series', 'Her eight-volume American Songbook series for Verve Records — devoted to Gershwin, Porter, Rodgers and Hart, Ellington, Kern, Berlin, Arlen, and Mercer — is the definitive recording of the great American standards. Each set was produced with the relevant composer\'s approval.', 'The only thing better than singing is more singing.', 'Ella Fitzgerald',
                'Scat and Improvisation', 'Fitzgerald\'s scat improvisations — wordless vocal riffs derived from jazz instrumentalism — were technically equal to any saxophonist or trumpeter. Her live recordings, including <em>Ella in Berlin</em> (1960), document the improvisational genius she exercised nightly.'],
            ['Louis Armstrong', 'louis-armstrong', 'Louis Armstrong (1901–1971) is the founding figure of jazz as a soloist\'s art — the first musician to take improvisation from a group activity to an individual statement of virtuosity and personality.',
                'Hot Fives and Sevens', 'His 1925–1928 recordings with the Hot Five and Hot Seven established solo improvisation as jazz\'s central creative act. Tracks like <em>West End Blues</em> and <em>Potato Head Blues</em> are still studied and imitated.', 'Musicians don\'t retire; they stop when there\'s no more music in them.', 'Louis Armstrong',
                'Ambassador Satch', 'In his later career Armstrong became a global ambassador — touring for the State Department, appearing in Hollywood films, recording novelty songs and pop standards. Some jazz purists found this commercial, but his voice and humanity reached audiences that jazz never otherwise touched.'],
            ['Jay-Z', 'jay-z', 'Shawn Corey Carter, known as Jay-Z (born 1969), rose from the Marcy Houses housing project in Brooklyn to become one of the wealthiest and most influential figures in music and business.',
                'Reasonable Doubt and the Blueprint', '<em>Reasonable Doubt</em> (1996), self-released through Roc-A-Fella Records, is his most celebrated album — a street-level report from the crack era delivered with sophisticated internal rhyme schemes. <em>The Blueprint</em> (2001) revitalized soul sampling and influenced a decade of hip-hop production.', 'I\'m not a businessman, I\'m a business, man.', 'Jay-Z',
                'Business and Legacy', 'Jay-Z built Roc Nation into a full-spectrum entertainment company. His public disagreement with streaming economics led to the launch of Tidal. His marriage to Beyoncé produced one of popular music\'s most scrutinized creative partnerships.'],
            ['Missy Elliott', 'missy-elliott', 'Missy Elliott (born 1971) is the most inventive and stylistically diverse female rapper in hip-hop history — a producer, songwriter, and visual artist whose influence on both rap and R&B is incalculable.',
                'Supa Dupa Fly', 'Her debut <em>Supa Dupa Fly</em> (1997), produced entirely by Timbaland, introduced a vocabulary of production — syncopated stuttering beats, elastic bass, playground chants — that sounded unlike anything before it. The video, shot through a fisheye lens with Elliott in an inflatable suit, is one of hip-hop\'s iconic images.', 'I always say, work hard and stay humble.', 'Missy Elliott',
                'Under Construction and Cultural Impact', 'Elliott\'s music mapped hip-hop\'s intersection with R&B, funk, and electronic music. She was inducted into the Rock and Roll Hall of Fame in 2023, the first female rapper to receive that honour.'],
            ['Lauryn Hill', 'lauryn-hill', 'Lauryn Hill (born 1975) released <em>The Miseducation of Lauryn Hill</em> (1998) and essentially disappeared from mainstream music — leaving behind what many consider the greatest album in hip-hop history.',
                'Fugees and Solo Debut', 'As the creative force of the Fugees, Hill co-wrote and largely produced their breakthrough <em>The Score</em> (1996). Her solo album arrived two years later, fusing neo-soul, reggae, R&B, and hip-hop in a meditation on love, religion, and racial identity.', 'I had to stop playing the game and start living my principles.', 'Lauryn Hill',
                'Miseducation\'s Legacy', 'The album won five Grammy Awards, including Album of the Year — the first hip-hop album to win. Hill\'s subsequent withdrawal from the music industry has become as mythologized as the record itself.'],
            ['André 3000', 'andre-3000', 'André 3000 — André Lauren Benjamin — is half of Outkast and the most unanimously celebrated lyricist of his generation, whose eccentric curiosity has taken him from Southern hip-hop to jazz flute recordings.',
                'Outkast and Speakerboxxx', 'With Big Boi, André formed Outkast in Atlanta in the early 1990s. <em>Aquemini</em> (1998) and <em>Stankonia</em> (2000) pushed Southern hip-hop into psychedelic funk territory; <em>Speakerboxxx/The Love Below</em> (2003) was a double album on which André\'s disc explored jazz, pop, and electronic music.', 'Be yourself, don\'t take anyone\'s shit, and never let them take you alive.', 'André 3000',
                'New Blue Sun', 'His 2023 debut solo album <em>New Blue Sun</em> — an hour of meditative flute improvisation with no rapping — was one of the most discussed and argued-over records of the year, a deliberate and successful refusal of expectation.'],
            ['Wu-Tang Clan', 'wu-tang-clan', 'Wu-Tang Clan — the Staten Island collective centred on RZA, GZA, Ol\' Dirty Bastard, Method Man, Raekwon, Ghostface Killah, and others — are the most influential hip-hop collective in history.',
                'Enter the Wu-Tang', '<em>Enter the Wu-Tang (36 Chambers)</em> (1993) was recorded on a budget of less than $40,000 and sounded like nothing before it: horror movie samples, kung-fu dialogue, dense multi-syllabic rhyme schemes, and RZA\'s minimal, cavernous production. It permanently changed hip-hop\'s aesthetic.',
                'Legacy', 'Once Upon a Time in Shaolin — an album of which only one copy was pressed — remains one of music\'s most discussed art objects. The collective\'s influence on production, lyrical complexity, and hip-hop mythology is inescapable.', 'Cash rules everything around me.', 'Wu-Tang Clan'],
            ['PJ Harvey', 'pj-harvey', 'Polly Jean Harvey (born 1969) is the only artist to have won the Mercury Prize twice — for <em>Stories from the City, Stories from the Sea</em> (2001) and <em>Let England Shake</em> (2011) — and the most celebrated British rock musician of her generation.',
                'Dry and Rid of Me', 'Her debut <em>Dry</em> (1992) announced a confrontational, emotionally raw rock sound rooted in blues, feminism, and a visceral physicality. <em>Rid of Me</em> (1993), produced by Steve Albini, is one of the most extreme rock albums by a mainstream artist.', 'I\'ve always wanted to be in places that are a little bit wrong.', 'PJ Harvey',
                'Let England Shake', '<em>Let England Shake</em> is a meditation on war, nationalism, and British imperial decline — a song cycle that quotes folk music, poetry, and military history. Critics consider it her masterpiece.'],
            ['Talking Heads', 'talking-heads', 'Talking Heads — David Byrne, Tina Weymouth, Chris Frantz, and Jerry Harrison — fused punk minimalism with African polyrhythm, funk, and art-school conceptualism to create one of the most original bodies of work in American music.',
                'Fear of Music and Remain in Light', '<em>Fear of Music</em> (1979) introduced a paranoid, rhythmically complex sound; <em>Remain in Light</em> (1980), produced with Brian Eno and drawing heavily on Fela Kuti and Afrobeat, is considered one of the defining records of the post-punk era.', 'I feel weird if I\'m not making something.', 'David Byrne',
                'Stop Making Sense', 'The 1984 concert film <em>Stop Making Sense</em>, directed by Jonathan Demme, is the most celebrated rock concert film ever made. Byrne\'s big suit, the expanding band, the choreography — it turned a performance into an artwork.'],
            ['The Chemical Brothers', 'chemical-brothers', 'Tom Rowlands and Ed Simons, the Chemical Brothers, brought big beat — a collision of breakbeat hip-hop and acid house — to arenas in the late 1990s and created the template for electronic live performance.',
                'Exit Planet Dust and Dig Your Own Hole', '<em>Exit Planet Dust</em> (1995) and <em>Dig Your Own Hole</em> (1997) established big beat\'s vocabulary: distorted breakbeats, psychedelic samples, collaborations with vocalists including Noel Gallagher and Beth Orton.', 'We just follow our ears. If it sounds right, it\'s right.', 'Tom Rowlands',
                'Sustained Career', 'Unusually for electronic artists, the Chemical Brothers have sustained a major career for thirty years, with <em>No Geography</em> (2019) winning the Grammy for Best Dance/Electronic Album. Their Glastonbury headline sets are legendary.'],
            ['Charlie Parker', 'charlie-parker', 'Charlie Parker (1920–1955) — Bird — is the central figure of bebop, the jazz revolution of the 1940s, and one of the most technically gifted improvising saxophonists who ever lived.',
                'The Birth of Bebop', 'Parker\'s late-night sessions at Minton\'s Playhouse in Harlem with Dizzy Gillespie, Thelonious Monk, and Kenny Clarke created a new jazz language — faster, more harmonically complex, deliberately difficult to imitate. Bebop was anti-entertainment: it was art music.', 'Music is your own experience, your thoughts, your wisdom. If you don\'t live it, it won\'t come out of your horn.', 'Charlie Parker',
                'Legacy and Loss', 'Parker was a heroin addict for most of his adult life and died at thirty-four. The coroner estimated his age at fifty-three. His recordings — made on acetate in brief, underfunded sessions — remain the canonical texts of bebop.'],
            ['Elvis Presley', 'elvis-presley', 'Elvis Presley (1935–1977) was the first rock and roll star — a white Southerner who absorbed Black rhythm and blues and gospel and gave it a physical, sexual energy that American television was not prepared for.',
                'Sun Records and the Television Appearances', 'His 1954-1955 recordings at Sun Studio in Memphis with Scotty Moore and Bill Black — <em>That\'s All Right</em>, <em>Mystery Train</em> — created rockabilly. His 1956 appearances on <em>The Ed Sullivan Show</em> were broadcast to sixty million people.', 'Truth is like the sun. You can shut it out for a time, but it ain\'t goin\' away.', 'Elvis Presley',
                'Hollywood and Decline', 'After his army service, Elvis was steered by Colonel Tom Parker toward Hollywood films and away from musical development. His 1968 television comeback special and the subsequent American Sound sessions briefly restored his artistic credibility.'],
            ['Whitney Houston', 'whitney-houston', 'Whitney Houston (1963–2012) possessed one of the most technically accomplished voices in popular music — a soprano of extraordinary power and precision trained in the gospel tradition of Newark, New Jersey.',
                'The Voice', 'Her debut album (1985) produced three number-one singles. <em>I Will Always Love You</em>, recorded for the 1992 film <em>The Bodyguard</em>, became the best-selling physical single by a woman in history.', 'I decided long ago never to walk in anyone\'s shadow; if I fail, or if I succeed at least I\'ll live as I believe.', 'Whitney Houston',
                'Tragedy', 'Houston\'s struggles with substance abuse were well-documented in later life. Her death by accidental drowning in a Beverly Hills hotel bathtub in 2012, on the eve of the Grammy Awards, stunned the music world.'],
            ['Freddie Mercury', 'freddie-mercury', 'Freddie Mercury (1946–1991) was Queen\'s vocalist and principal songwriter — a theatrical genius who combined operatic range, extraordinary stage presence, and a gift for melody that produced some of rock\'s most durable anthems.',
                'Bohemian Rhapsody and Live Aid', '<em>Bohemian Rhapsody</em> (1975) — six minutes of mock opera, hard rock, and a cappella harmonies — remained on the UK charts for nine weeks. Queen\'s 1985 Live Aid performance at Wembley Stadium is widely considered the greatest concert performance in rock history.', 'I won\'t be a rock star. I will be a legend.', 'Freddie Mercury',
                'Legacy', 'Mercury died of AIDS-related pneumonia at forty-five. His posthumous prominence, fuelled by the 2018 biopic, has introduced his work to new generations. The Freddie Mercury Tribute Concert in 1992 remains one of the largest rock concerts ever staged.'],
            ['Madonna', 'madonna', 'Madonna (born 1958) is the best-selling female music artist of all time and the entertainer who most clearly redefined what it meant to control one\'s image, career, and sexuality in a corporate music industry.',
                'Material Girl and Reinvention', 'Her 1983 debut introduced a fashion-forward dancepop that colonised radio. She spent the following four decades reinventing herself — from <em>Like a Prayer</em>\'s gospel controversy to the house music of <em>Erotica</em> to the electronic minimalism of <em>Music</em>.', 'I\'m tough, I\'m ambitious, and I know exactly what I want. If that makes me a bitch, okay.', 'Madonna',
                'Business and Control', 'Madonna founded Maverick Records in 1992 to secure artistic and business control over her work — years before Taylor Swift made the concept mainstream. Her influence on pop performance, fashion, and LGBTQ+ visibility is still unfolding.'],
            ['Sly Stone', 'sly-stone', 'Sylvester Stewart, performing as Sly Stone, led Sly and the Family Stone — one of the first racially and sexually integrated pop acts — and created a vision of utopian funk that influenced everything from hip-hop to rave culture.',
                'Everyday People and Stand!', '<em>Stand!</em> (1969) and the Woodstock performance that followed made Sly Stone the biggest Black artist in America. <em>Everyday People</em> and <em>I Want to Take You Higher</em> were anthems of unity.', 'Different strokes for different folks.', 'Sly Stone',
                'There\'s a Riot Goin\' On', 'His 1971 response to Black Power — <em>There\'s a Riot Goin\' On</em> — replaced utopia with paranoia. Recorded during a period of heavy drug use, its murky, dirge-like funk anticipated hip-hop\'s sample aesthetic by twenty years.'],
            ['Janis Joplin', 'janis-joplin', 'Janis Joplin (1943–1970) was the first female rock superstar — a white woman from Port Arthur, Texas who sang the blues with a raw emotional power that electrified audiences and unnerved record executives in equal measure.',
                'Big Brother and the Holding Company', 'Her Monterey Pop Festival performance in 1967 made her a national star overnight. Her recordings with Big Brother and the Holding Company, particularly <em>Piece of My Heart</em>, documented a voice that treated itself as a percussive instrument of extreme suffering.', 'Don\'t compromise yourself. You\'re all you\'ve got.', 'Janis Joplin',
                'Pearl', 'Her posthumous album <em>Pearl</em> (1971), completed after her heroin overdose at twenty-seven, contained <em>Me and Bobby McGee</em> — her only number-one single. She remains the template for every female rock vocalist who has followed.'],
        ];

        return \array_values(\array_map(static function(array $a) use ($musicPageUuid): array {
            [$name, $slug, $intro, $sec1Title, $sec1Text, $quote, $quoteAttr, $sec2Title, $sec2Text] = $a;

            return [
                'locale' => 'en',
                'title' => $name,
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $musicPageUuid, 'path' => '/music'],
                    'suffix' => '/' . $slug,
                ],
                'article' => '<p>' . $intro . '</p>',
                'blocks' => [
                    [
                        'type' => 'section',
                        'title' => $sec1Title,
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>' . $sec1Text . '</p>'],
                            ['type' => 'quote', 'text' => '<p>' . $quote . '</p>', 'attribution' => $quoteAttr],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => $sec2Title,
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>' . $sec2Text . '</p>'],
                        ],
                    ],
                ],
            ];
        }, $artists));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getAdditionalBlogArticles(string $blogPageUuid): array
    {
        $articles = [
            ['Headless CMS vs Traditional CMS: Making the Right Choice', 'headless-vs-traditional-cms',
                '<p>Choosing between a headless and a traditional CMS is one of the most consequential architectural decisions a content team can make. The right answer depends on your team\'s skills, your delivery channels, and your editorial workflow.</p>',
                'What Headless Means', '<p>A headless CMS stores and manages content but has no built-in frontend. Content is delivered via API to any consumer — a React app, a mobile app, an IoT device. This flexibility comes at the cost of complexity: developers must build and maintain the presentation layer.</p>',
                'When Traditional Wins', '<p>Traditional CMSes like Sulu bundle content management and frontend rendering in one system. For content teams without dedicated frontend developers, this is a significant advantage. Template-driven rendering, inline preview, and editorial controls are all built in.</p>'],
            ['Content Modeling: The Foundation of Scalable CMS Architecture', 'content-modeling-foundations',
                '<p>Content modeling — defining the types, fields, and relationships that make up your content — is the most underestimated aspect of CMS implementation. A poor content model causes problems that no template or workflow can fix.</p>',
                'Think in Types, Not Pages', '<p>The most common mistake is modeling content as pages rather than as structured types. A news article has a title, a body, an author, a publication date, and tags. These should be separate fields, not a blob of HTML in a text editor.</p>',
                'Relationships and References', '<p>Model relationships explicitly. An article references an author, who is a separate content type with their own fields. This separation lets you reuse content across contexts and update it in one place.</p>'],
            ['SEO Fundamentals for Content Teams', 'seo-fundamentals-content-teams',
                '<p>SEO is not a technical afterthought — it is a content discipline. The teams who produce the best-performing content understand search intent, keyword research, and on-page signals well enough to make those decisions during the writing process.</p>',
                'Search Intent Comes First', '<p>Before writing a word, understand why someone searches a given query. Informational queries want explanations; transactional queries want to buy something; navigational queries want a specific website. Match your content format to the intent.</p>',
                'Technical SEO in a CMS Context', '<p>Ensure your CMS generates clean URLs, proper canonical tags, and sitemap entries automatically. Meta titles and descriptions should be editable per page. Structured data markup — particularly for articles, products, and FAQs — provides search engines richer signals.</p>'],
            ['Multilingual Content Strategy: Building for Global Audiences', 'multilingual-content-strategy',
                '<p>Publishing in multiple languages is not just a translation exercise. A genuine multilingual content strategy requires thinking about markets, cultural context, local SEO, and editorial workflow from the start.</p>',
                'Translation vs Localisation', '<p>Translation converts words; localisation adapts meaning. A slogan that resonates in English may be meaningless or offensive in Japanese. Work with native speakers who understand your industry, not just translators who understand your words.</p>',
                'Technical Implementation', '<p>Your CMS should handle hreflang tags automatically, maintain separate URL structures per locale (subdirectories or subdomains, not query parameters), and allow each locale version to have independent metadata and content — not just translated text fields.</p>'],
            ['Writing for the Web: Principles That Actually Work', 'writing-for-the-web',
                '<p>Web writing is not magazine writing or academic writing. Readers scan before they read. They arrive mid-journey, already knowing something about the topic. They leave the moment they lose interest.</p>',
                'Frontload Your Value', '<p>Put the most important information first. This is called the inverted pyramid structure, borrowed from journalism. Your first sentence should tell the reader whether the page is worth their time. Your second sentence should give them a reason to stay.</p>',
                'Scannability and Structure', '<p>Use short paragraphs — three sentences maximum. Use subheadings that can be scanned without reading the body text. Use bullet lists for genuinely list-like information. Bold terms that readers might search for on the page.</p>'],
            ['Digital Asset Management: Getting Control of Your Media', 'digital-asset-management',
                '<p>As organisations produce more content, the problem of finding, reusing, and governing media files becomes increasingly expensive. A good DAM system pays for itself by eliminating duplicated work and licensing risk.</p>',
                'The Chaos Phase', '<p>Most organisations start with network drives, then move to Dropbox folders, then accumulate SharePoint libraries of unknowable depth. By the time they implement a DAM, they have tens of thousands of assets of which perhaps twenty percent are usable.</p>',
                'Integration with CMS', '<p>A DAM that integrates with your CMS allows editors to browse and insert approved assets directly from the content editor. Usage metadata flows back to the DAM, so you can see which assets are in use and on which pages. This matters enormously for rights management.</p>'],
            ['Core Web Vitals: What Content Teams Need to Know', 'core-web-vitals-content-teams',
                '<p>Core Web Vitals — Largest Contentful Paint, Cumulative Layout Shift, and Interaction to Next Paint — are Google\'s user experience metrics that influence search ranking. Content teams affect all three, often without realising it.</p>',
                'Images and LCP', '<p>LCP measures how long the largest visible element takes to load. On editorial pages, this is almost always an image. Use correctly sized images, modern formats (WebP, AVIF), and lazy loading for below-the-fold images.</p>',
                'Layout Stability and CLS', '<p>CLS measures how much the page layout shifts while loading. Common causes: images without declared dimensions, embeds that inject themselves, and fonts that swap after load. Editorial teams contribute to this by pasting embeds without dimensions.</p>'],
            ['Content Governance: Keeping Quality High at Scale', 'content-governance-at-scale',
                '<p>As content volume grows, governance — the policies, processes, and people responsible for quality — becomes the difference between a content operation and a content mess.</p>',
                'Defining Standards', '<p>Governance starts with standards: what makes content publishable? Define minimum requirements for titles, meta descriptions, image alt text, and internal links. Make these requirements enforced in the CMS where possible, and editorial policy where not.</p>',
                'Audit and Maintenance', '<p>Schedule quarterly content audits. Flag pages with declining traffic, broken links, or outdated information. Have a policy for archiving content that no longer serves a purpose. Content that is wrong is worse than no content at all.</p>'],
            ['AI Content Tools: What They\'re Good at and Where They Fail', 'ai-content-tools-capabilities-limits',
                '<p>AI writing tools have genuine capabilities and genuine limitations. Understanding both prevents you from either dismissing them as gimmicks or treating them as replacements for human editorial judgment.</p>',
                'Where AI Adds Value', '<p>AI is excellent at producing first drafts quickly, maintaining consistent formatting, generating metadata, summarising long documents, and spotting stylistic inconsistencies. These are high-volume, low-creativity tasks that drain editorial time.</p>',
                'Where AI Falls Short', '<p>AI cannot verify facts. It cannot interview sources. It does not know your brand voice from a competitor\'s unless explicitly trained. It produces confident-sounding text regardless of accuracy. Every AI-generated piece requires a human editor with domain expertise before publication.</p>'],
            ['Building a Brand Voice Guide That Content Teams Actually Use', 'brand-voice-guide',
                '<p>A brand voice guide sits in a shared drive until someone remembers it exists, then gets ignored because it is thirty pages of abstract principles that do not tell a writer what to do when they are staring at a blank document.</p>',
                'What a Useful Voice Guide Contains', '<p>Contrast examples: this versus that. Not "we sound warm" but "we say \'let us know\' not \'please do not hesitate to contact us\'." Not "we avoid jargon" but a list of the twenty terms your industry uses that your customers do not understand.</p>',
                'Living Documents', '<p>Update the guide every time a word or phrase is debated in an editorial review. These debates are the raw material of a voice. A guide written once and never touched becomes archaeology, not guidance.</p>'],
            ['Internal Linking: The Most Underused SEO Strategy', 'internal-linking-strategy',
                '<p>Internal links distribute authority across your site, help search engines discover and index content, and guide readers to related material that serves their needs. Most organisations do this haphazardly, if at all.</p>',
                'Link with Intent', '<p>Each internal link should answer the question: why would a reader on this page benefit from visiting that page? A link from a guide to a product page is useful. A link from an article to the homepage is not.</p>',
                'Audit and Structure', '<p>Map your most valuable pages and ensure they receive internal links from content in adjacent topics. Orphan pages — pages with no internal links pointing to them — cannot be discovered by search engines or readers.</p>'],
            ['Content Repurposing: One Idea, Many Formats', 'content-repurposing',
                '<p>Creating original content is expensive. Repurposing turns one research investment into multiple pieces of content across different formats and channels, multiplying your return without proportionally increasing your cost.</p>',
                'The Content Core', '<p>Start with a substantial piece: a long-form guide, a research report, a detailed case study. This is your content core. From it, extract blog posts, social media threads, email newsletters, slide decks, short videos, and podcast episodes.</p>',
                'Adaptation, Not Copying', '<p>Repurposing is not copying and pasting. Each format has its own conventions. A Twitter thread is punchy fragments. A slide deck is visual assertions. A podcast is conversational. Adapt the ideas to the format rather than forcing one format into another.</p>'],
            ['Editorial Calendar Planning: From Reactive to Strategic', 'editorial-calendar-planning',
                '<p>A reactive content team publishes whatever seems pressing this week. A strategic content team publishes against a plan that serves audience needs, supports business objectives, and builds over time into a coherent body of work.</p>',
                'The Quarterly View', '<p>Plan at the quarter level for major content initiatives — guides, research, campaign content. Plan at the month level for recurring content — newsletters, roundups, updates. Plan at the week level for current events and reactive content.</p>',
                'Building in Flexibility', '<p>Leave capacity in your calendar for reactive opportunities. A content plan that leaves no room for current events becomes a straitjacket. Reserve fifteen to twenty percent of capacity for work that emerges from the news cycle.</p>'],
            ['Accessibility in Web Content: Beyond Compliance', 'accessibility-web-content',
                '<p>Web accessibility is often framed as a legal requirement or a checklist. It is more useful to think of it as a quality signal — content that is accessible is better written, better structured, and clearer for everyone.</p>',
                'Alt Text Is Not Optional', '<p>Alternative text for images serves screen reader users, people with images disabled, and search engine crawlers simultaneously. Write alt text that describes what the image shows and why it is in this context — not just what it depicts.</p>',
                'Reading Level and Plain Language', '<p>The Web Content Accessibility Guidelines (WCAG) recommend reading level appropriate to the audience. Plain language — short sentences, common words, active voice — improves accessibility and comprehension for everyone, including native speakers without disabilities.</p>'],
            ['Performance Optimisation for Content-Heavy Sites', 'performance-optimisation-content-sites',
                '<p>Content-heavy sites accumulate images, embeds, scripts, and stylesheets that slow page load progressively and imperceptibly. By the time someone notices the problem, it is systemic.</p>',
                'Image Pipeline', '<p>Establish an image pipeline that automatically converts uploads to modern formats, generates multiple sizes for responsive images, and strips unnecessary metadata. This single change typically reduces image payload by fifty to seventy percent.</p>',
                'Third-Party Scripts', '<p>Audit third-party scripts quarterly. Marketing, analytics, chat, and personalisation tools accumulate. Each adds HTTP requests and JavaScript parse time. Remove scripts whose value cannot be demonstrated, and load the remainder asynchronously.</p>'],
            ['Taxonomy Design: Organising Content for Humans and Machines', 'taxonomy-design',
                '<p>Taxonomy — the categories, tags, and labels you apply to content — determines whether readers can browse and filter effectively, whether search engines understand your content relationships, and whether your editorial team can manage content at scale.</p>',
                'Categories vs Tags', '<p>Categories are structural — broad groupings that define your content\'s main topics. Tags are descriptive — specific terms that apply to individual pieces. A category is "Technology"; a tag is "PHP 8.4". Use categories sparingly and tags generously.</p>',
                'Governance of Taxonomy', '<p>Unmanaged taxonomies degrade rapidly. Tags proliferate, synonyms accumulate, and categories become inconsistent. Designate someone responsible for taxonomy governance. Review and merge tags quarterly. Document your category definitions.</p>'],
            ['The Business Case for Content Strategy', 'business-case-content-strategy',
                '<p>Content strategy is sometimes perceived as a creative luxury. It is not. It is a business function with measurable outputs: organic traffic, lead generation, customer retention, brand trust, and sales enablement.</p>',
                'Measuring Content ROI', '<p>Define what success looks like before publishing. Track organic sessions, conversion rate from organic, content-assisted revenue, time on page, and return visit rate. Without baseline metrics, you cannot demonstrate improvement.</p>',
                'The Cost of No Strategy', '<p>Organisations without content strategy spend more, publish less effectively, and retire content prematurely because no one remembers why it was created. Document the purpose of every piece of content at the time of creation.</p>'],
            ['Video Content and CMS Integration', 'video-content-cms-integration',
                '<p>Video is the highest-engagement content format on most channels. Integrating video into a CMS-driven content strategy requires decisions about hosting, metadata, transcription, and accessibility that many organisations defer until they become problems.</p>',
                'Self-Host vs Platform', '<p>Hosting video on your own infrastructure gives you data control and removes platform dependency but requires bandwidth and a video player. Platforms like Vimeo and YouTube handle delivery but own the audience relationship. Most organisations use both: platforms for reach, self-hosting for gated and sensitive content.</p>',
                'Transcription and SEO', '<p>Search engines cannot index video content without a transcript. Provide transcripts for all videos. Beyond SEO, transcripts make video accessible to deaf users, to viewers in sound-sensitive environments, and to anyone who reads faster than the presenter speaks.</p>'],
            ['Schema Markup for Editorial Content', 'schema-markup-editorial-content',
                '<p>Schema markup — structured data added to page HTML — communicates content meaning to search engines in a language they can act on. For editorial content, the right schema types can produce rich results in search.</p>',
                'Article and NewsArticle Schema', '<p>The Article schema type tells search engines who wrote a piece, when it was published and modified, and what organization produced it. NewsArticle adds eligibility for news-specific search features. Both improve how your content appears in results.</p>',
                'FAQ and HowTo', '<p>FAQ schema turns question-and-answer content into expandable results in Google search. HowTo schema displays step-by-step instructions with images directly in results. Both dramatically increase the visual footprint of your content on the results page.</p>'],
            ['A/B Testing Content: What to Test and How to Interpret Results', 'ab-testing-content',
                '<p>A/B testing removes opinion from content decisions. Instead of debating whether a headline is better, you can measure it. The discipline is valuable but requires statistical rigour to produce actionable conclusions.</p>',
                'What to Test', '<p>High-impact variables with clear success metrics. Headlines (because they determine click-through from search and social). Calls to action (because they determine conversion rate). Content length (because it affects bounce rate and time on page). Images (because they affect engagement and LCP).', 'Statistical Significance', '<p>Do not draw conclusions from small samples. A test that ran for three days with two hundred visits is not statistically significant, regardless of which variant appeared to win. Wait for sample sizes that give you ninety-five percent confidence before acting on results.</p>'],
            ['Building a Remote Content Team', 'building-remote-content-team',
                '<p>Remote content teams have become the norm rather than the exception. The tools exist; the process discipline required to use them well is harder to establish and maintain.</p>',
                'Documentation Culture', '<p>Remote teams succeed on documentation. When you cannot tap someone on the shoulder, you need processes, templates, and standards documented well enough that a new team member can produce compliant work without asking. Invest in documentation before you invest in tools.</p>',
                'Async by Default', '<p>Design your workflows to be asynchronous by default. Editorial reviews done over documents with comments, not synchronous calls. Briefs written with enough context to be actioned without a briefing meeting. This makes the team more productive and more timezone-flexible.</p>'],
            ['Content Migration: Moving Without Losing', 'content-migration',
                '<p>Content migration — moving content from one CMS to another, or from one URL structure to another — is one of the highest-risk activities in digital publishing. Done poorly, it causes months of SEO regression and editorial chaos.</p>',
                'Audit Before You Migrate', '<p>Do a full content audit before migrating anything. Categorise every page: keep, redirect, update, or retire. Do not migrate content that should be retired just because migrating everything is easier. Every piece of bad content you migrate becomes someone\'s problem later.</p>',
                'Redirect Planning', '<p>Map every old URL to its new destination before going live. Implement 301 redirects on day one of launch. Test them. Test them again. A missing redirect on a high-traffic URL transfers its authority to a dead end and costs you traffic that can take months to recover.</p>'],
            ['Content Localisation vs Content Translation', 'localisation-vs-translation',
                '<p>Localisation and translation are related but distinct activities. Understanding the difference prevents expensive mistakes and helps you staff and budget your multilingual content operation appropriately.</p>',
                'Translation: Words', '<p>Translation converts text from one language to another with fidelity to meaning. A good translator understands both languages and, ideally, the subject matter. Machine translation can handle high-volume, low-stakes content at low cost; human translation is required for anything that represents your brand.</p>',
                'Localisation: Context', '<p>Localisation adapts content for a specific market. This includes dates, currencies, measurements, legal requirements, cultural references, imagery, and tone. A product page localised for Germany includes German data protection notices, prices in euros, and metric measurements — not just translated text.</p>'],
            ['Podcast as Content Engine: The Editorial Case', 'podcast-as-content-engine',
                '<p>A podcast is not just an audio product. Produced well, it is a content engine that yields transcripts, quote cards, blog posts, newsletter content, and social clips from a single recording session.</p>',
                'The Transcript Pipeline', '<p>Every episode produces a transcript. A transcript, lightly edited, becomes a blog post. The blog post is indexed by search engines. The most quotable moments become social content. The best clips become short-form video. One recording becomes six pieces of content.</p>',
                'SEO Value', '<p>Podcasts are not directly indexed by search engines. Their transcripts are. A well-structured transcript with good headings, question-and-answer format, and relevant keywords can rank for long-tail queries that conventional blog content would not target.</p>'],
            ['Email Newsletters and CMS Integration', 'email-newsletters-cms-integration',
                '<p>Email newsletters and CMS content should not live in separate silos. A well-integrated workflow lets your editorial team write once and publish across channels without duplicating effort or diverging from a single source of truth.</p>',
                'Content Reuse Patterns', '<p>The most common integration pattern: articles are published in the CMS, then summaries are pulled into an email template via API or RSS. This keeps the canonical version of content in the CMS and ensures email subscribers see current content, not a stale copy.</p>',
                'Tracking and Attribution', '<p>Link every email to a UTM-tagged URL so your analytics can distinguish email-sourced traffic from organic search and direct. This makes newsletter ROI visible and lets you adjust frequency and content mix based on what drives the most valuable traffic.</p>'],
            ['The Editorial Brief: Making Briefs That Writers Actually Follow', 'editorial-brief-best-practices',
                '<p>A brief that a writer ignores is no brief at all. Most briefs fail because they communicate what the editor wants rather than what the writer needs to succeed.</p>',
                'What a Good Brief Contains', '<p>Target audience and their specific question. The search query this piece should rank for. The format (list, how-to, guide, narrative). The word count range. Key points to cover. Sources to consult. Points of differentiation from existing content on this topic.</p>',
                'Briefs as Conversations', '<p>Share briefs before the writer starts, not as a handoff but as an invitation. Ask the writer if they have questions. The brief-as-dialogue catches misunderstandings before they become revision rounds.</p>'],
            ['Managing Freelance Content Contributors', 'managing-freelance-writers',
                '<p>Freelance writers are a force-multiplier for content operations. Managing them well is its own discipline — different from managing employees and harder to get right.</p>',
                'Onboarding That Works', '<p>Invest two to three hours in onboarding a new freelancer. Walk them through your voice guide, your audience, your competitors, your best-performing content, and your revision process. This investment returns itself by the second assignment.</p>',
                'Feedback That Improves', '<p>Specific feedback makes writers better; generic feedback demoralises them. Not "this needs to be more lively" but "this sentence is passive — try the active voice: \'Google changed\' not \'a change was made by Google\'." Make your feedback something a writer can act on immediately.</p>'],
            ['Content Personalisation: When It Works and When It Doesn\'t', 'content-personalisation',
                '<p>Content personalisation — showing different content to different visitors based on who they are, what they have done, or where they are — is technically impressive and often produces surprisingly modest results.</p>',
                'The Maintenance Burden', '<p>Personalisation multiplies the number of content variations that need to be created, tested, and maintained. A site with ten audience segments and fifty personalised pages has effectively become five hundred pages, each requiring editorial attention.</p>',
                'Where It Reliably Works', '<p>Personalisation reliably improves results in three scenarios: returning visitor recognition (showing content relevant to previous visits), geographic targeting (showing local offers and content), and explicit preference capture (letting users tell you what they care about). Start with these before building more complex systems.</p>'],
            ['Content Security: Protecting What You Publish', 'content-security',
                '<p>Content security is frequently overlooked until it becomes a crisis. A CMS manages your brand\'s public voice and often your customers\' data — both are targets for attackers.</p>',
                'Access Control', '<p>Apply the principle of least privilege: every user has exactly the access they need to do their job. Writers should not have publish rights. Publishers should not have template editing rights. Admins should not use their admin account for daily editorial tasks.</p>',
                'Dependency Management', '<p>CMS plugins, themes, and dependencies are common attack vectors. Establish a process for reviewing and applying security updates within forty-eight hours of release. Audit installed plugins quarterly and remove any that are unused or unmaintained.</p>'],
            ['GDPR and Content Management: Practical Compliance', 'gdpr-content-management',
                '<p>GDPR compliance for content teams is not primarily a legal exercise — it is a data management discipline that affects how you capture, store, and retire content that contains personal data.</p>',
                'Content That Contains Personal Data', '<p>Case studies, testimonials, author profiles, and event recordings may contain personal data subject to data subject rights. Document where personal data appears in content and have a process for handling deletion requests. A right-to-erasure request should not result in a broken page.</p>',
                'Consent and Forms', '<p>Every form on your website that captures email addresses or other personal data requires a legal basis for processing. Consent is the most common basis, but consent must be freely given, specific, informed, and unambiguous — a pre-ticked checkbox does not qualify.</p>'],
            ['Voice Search and Conversational Content', 'voice-search-optimisation',
                '<p>Voice search changes the character of queries. Typed searches are terse (<em>best cms sulu</em>); voice searches are conversational (<em>what is the best CMS for a Symfony project?</em>). Content optimised for voice answers questions in natural language.</p>',
                'Question-Based Content', '<p>Structure content around the questions your audience asks. Use the questions as headings. Answer them directly and completely in the first paragraph beneath the heading. This structure serves both voice search and the FAQ rich results that voice devices read aloud.</p>',
                'Featured Snippets', '<p>Voice search results typically come from featured snippets — the box Google shows above organic results. Optimise for snippets by providing concise, well-formatted answers to specific questions. Tables, numbered lists, and direct answers perform well for snippets.</p>'],
            ['Long-Form vs Short-Form Content: Choosing the Right Length', 'content-length-strategy',
                '<p>The right content length is the length required to fully answer the reader\'s question — no more, no less. The question is not whether long-form or short-form is better; it is what the reader needs to leave the page satisfied.</p>',
                'When Long-Form Wins', '<p>Long-form content outperforms short-form for complex topics, competitive keywords, and content intended to establish authority. A two-thousand-word guide to content modeling will rank higher and convert better than two hundred words that skim the surface.</p>',
                'When Short-Form Wins', '<p>Short-form wins when the reader has a specific, factual question with a concise answer. Trying to inflate a two-hundred-word answer into two thousand words damages user experience and signals to search engines that the content is padded.</p>'],
            ['Content Analytics: Metrics That Matter', 'content-analytics-metrics',
                '<p>Content teams drown in metrics. Page views, sessions, bounce rate, time on page, scroll depth, conversions, assisted conversions, return visits — the challenge is not finding data but deciding which data drives decisions.</p>',
                'Choose Metrics That Match Goals', '<p>If your goal is brand awareness, measure impressions, reach, and new visitor percentage. If your goal is lead generation, measure conversion rate and cost per lead from content channels. If your goal is SEO, measure organic sessions, keyword rankings, and backlinks earned.</p>',
                'Vanity vs Action Metrics', '<p>Page views are a vanity metric unless combined with context. A page with ten thousand views and zero conversions may be underperforming a page with one thousand views and fifty conversions. Report on metrics that indicate whether content is doing its job, not just that it exists.</p>'],
            ['Structured Content: Writing for Machines as Well as Humans', 'structured-content-writing',
                '<p>Structured content — content stored in discrete, typed fields rather than unformatted blobs — is increasingly important as content needs to flow to multiple channels and be processed by AI systems.</p>',
                'Fields Are Contracts', '<p>When you define a title field as a maximum of sixty characters, that is a contract with every downstream system that consumes it. SEO tools expect sixty characters. Social media cards expect sixty characters. If a writer puts two hundred characters in that field, every consumer breaks.</p>',
                'Preparing for AI Consumption', '<p>AI systems that summarise, translate, or personalise your content work better with structured input. A clearly labelled abstract, a defined body, explicit authors and dates, and tagged categories give the model the context it needs to produce accurate outputs.</p>'],
            ['The Role of a Content Strategist in 2025', 'content-strategist-role-2025',
                '<p>The content strategist role has changed significantly as AI tools have automated first-draft production and analytics have made content impact measurable in ways that were not previously possible.</p>',
                'What Changed', '<p>Content volume is no longer the constraint. Any team with AI tools can produce more content than their audience can consume. The constraint is now quality, relevance, and differentiation — which requires human judgment, domain expertise, and audience understanding.</p>',
                'The New Scope', '<p>In 2025, content strategists focus on AI content governance (setting policies for AI use and human review), audience research (understanding what readers actually need), and performance analysis (closing the loop between content production and business outcomes).</p>'],
            ['Working with Subject Matter Experts: Getting Content Out of Their Heads', 'working-with-smes',
                '<p>Subject matter experts know things your readers need to know but rarely write well or willingly. The content team\'s job is to extract their knowledge and turn it into content that serves the audience.</p>',
                'The Interview as First Draft', '<p>Treat every SME interaction as a raw material source. Record the conversation (with permission), transcribe it, and edit the transcript into readable prose. The SME reviews and corrects; the content team shapes. This process is faster than asking SMEs to write and produces better prose.</p>',
                'Reducing the Review Burden', '<p>SMEs will deprioritise content review unless you make it frictionless. Send a single focused question rather than a complete draft. Use comment threads rather than tracked changes. Set a clear deadline with a clear consequence (publication proceeds without their input).'],
            ['Content Operations: Systematising Your Content Work', 'content-operations',
                '<p>Content operations is the discipline of systematising content work — defining processes, choosing tools, measuring output, and building infrastructure that lets content teams scale without degrading quality.</p>',
                'Process Before Tools', '<p>Organisations that buy a new tool to solve a process problem get an expensive process problem with a tool attached. Define your ideal workflow first: who does what, in what order, with what inputs and outputs. Then choose tools that support that workflow.</p>',
                'The Content Supply Chain', '<p>Think of content production as a supply chain: briefing, research, writing, editing, review, approval, publication, distribution, and measurement. Each stage should have clear owners, inputs, outputs, and quality standards. Bottlenecks are visible and addressable when the chain is mapped.</p>'],
            ['Competitive Content Research: Learning from Your Rivals', 'competitive-content-research',
                '<p>Competitive content research is not copying your competitors — it is understanding where they are strong, where they are weak, and where there is space for differentiated content that they are not producing.</p>',
                'Mapping the Competitive Landscape', '<p>Start with a keyword gap analysis: which keywords do your competitors rank for that you do not? Which keywords do you rank for that they do not? The gap is your opportunity. The overlap is your competitive battlefield.</p>',
                'Quality Differentiation', '<p>If a competitor ranks for a term with thin, surface-level content, you can outrank them with something substantially better. If they have invested heavily in a topic with comprehensive, well-cited guides, competing on the same terms requires an equivalent investment.</p>'],
            ['Content Archiving: When to Retire vs Redirect vs Update', 'content-archiving-strategy',
                '<p>Published content does not age gracefully without maintenance. A systematic approach to content archiving prevents outdated content from damaging your authority and user experience.</p>',
                'The Decision Matrix', '<p>Evaluate content on two axes: traffic (is anyone visiting?) and accuracy (is the information still correct?). High traffic and accurate: maintain. High traffic and inaccurate: update immediately. Low traffic and accurate: leave or redirect. Low traffic and inaccurate: retire with a redirect to the best current alternative.</p>',
                'Redirects on Retirement', '<p>Never delete a page without redirecting it. Even low-traffic pages may have external links, internal links, or bookmarks. A redirect preserves those navigational paths and prevents a broken experience for anyone who arrives at the old URL.</p>'],
            ['Content Team Structure: Roles, Responsibilities, and Scale', 'content-team-structure',
                '<p>Content teams can be a single generalist or a department of fifty. Structure should follow function: what does your content operation need to do, and who is best placed to do it?</p>',
                'Core Roles', '<p>At minimum, a content operation needs a strategist (who decides what to create and why), a writer (who creates it), an editor (who ensures quality), and someone responsible for publishing and performance (who manages the CMS and reports on results). In small teams, one person covers multiple roles.</p>',
                'When to Specialise', '<p>Specialisation makes sense when a particular content type becomes high-volume. Dedicated social media managers, SEO specialists, video producers, and localisation coordinators add value when the volume of work justifies the dedicated resource.</p>'],
            ['MCP in Production: What Deployment Looks Like', 'mcp-production-deployment',
                '<p>Taking an MCP server from development to production involves decisions about authentication, network access, monitoring, and permission scoping that are not required in local testing.</p>',
                'Network and Authentication', '<p>The MCP endpoint must be accessible to the AI clients that will connect to it. For Claude.ai Projects, this means a publicly accessible HTTPS URL. For ChatGPT, similarly. This has security implications: ensure the endpoint is protected by OAuth and that Sulu user permissions are appropriately scoped.</p>',
                'Monitoring and Logging', '<p>Log every MCP tool call with the authenticated user, the tool name, and the outcome. This audit trail is essential for compliance, debugging, and understanding how AI assistants are using your content system. Monitor for unusual patterns — high-volume operations or operations outside business hours.'],
            ['Prompting AI for Content: What Works', 'ai-prompting-for-content',
                '<p>The quality of AI-generated content is a function of the quality of the prompt. Vague prompts produce generic content. Specific, contextualised prompts produce content that can serve as a genuine first draft.</p>',
                'Elements of a Strong Content Prompt', '<p>Target audience and their knowledge level. The specific question the piece should answer. The tone and voice (ideally with examples). Any points of view or claims the piece should avoid. The format — list, guide, narrative. The desired length. The call to action.</p>',
                'Giving AI Your Context', '<p>AI performs better when it knows your context. In an MCP-connected workflow, the AI has access to your actual CMS content — existing articles, templates, categories, and brand guidelines. This contextual awareness produces more relevant first drafts than prompting in isolation.</p>'],
            ['The Future of CMS: What Content Teams Should Expect', 'future-of-cms',
                '<p>The CMS market is changing faster than at any point since the WordPress explosion of the mid-2000s. AI integration, composable architecture, and the blurring line between content creation and content management are reshaping what a CMS is and does.</p>',
                'AI as a First-Class CMS Feature', '<p>Within three to five years, AI assistance will be a standard feature of every serious CMS — not an add-on, but a first-class capability embedded in the editorial workflow. The question is not whether your CMS will have AI features but whether those features will be genuinely useful or merely present.</p>',
                'Composable Architectures', '<p>The move toward composable architectures — selecting best-of-breed tools for each function and integrating them — will continue. This creates flexibility and vendor independence but increases the operational complexity of managing multiple systems. CMS vendors who succeed will be those who integrate easily with the composable ecosystem rather than trying to contain it.'],
            ['How Sulu Handles Multi-Webspace Deployments', 'sulu-multi-webspace-deployments',
                '<p>Sulu\'s webspace architecture allows a single Sulu installation to serve multiple independent websites — each with its own templates, navigation, domain, and locale configuration. For agencies and enterprises managing multiple brands, this is a significant operational advantage.</p>',
                'Webspace Configuration', '<p>Each webspace is defined in <code>config/webspaces/</code> as an XML file specifying its key, URLs, locales, template sets, and navigation contexts. The MCP server exposes all configured webspaces to connected AI assistants, which can then scope content operations to a specific webspace.</p>',
                'Content Isolation', '<p>Content in one webspace is not automatically visible in another. Pages, articles, and snippets exist within webspace scope. Media and contacts are shared across webspaces. Understanding this separation is essential for content operations teams managing multiple brands through a single Sulu installation.'],
            ['Sulu Template Architecture: A Developer\'s Guide', 'sulu-template-architecture',
                '<p>Sulu\'s template system combines XML configuration (defining content fields and validation) with Twig rendering (controlling HTML output). Understanding how these two layers interact is essential for building flexible, maintainable content types.</p>',
                'Template XML Structure', '<p>Each template is an XML file defining properties (fields like text, media, date) and blocks (typed content components). Properties appear in the template\'s fixed layout; blocks can be added, removed, and reordered by editors. The template XML is the contract between the CMS configuration and the Twig rendering.</p>',
                'Block Type Definitions', '<p>Block types are defined either inline in the template or in standalone XML files referenced with <code>&lt;type ref="block_name"/&gt;</code>. Shared block definitions are more maintainable: define once in <code>config/templates/blocks/</code> and reuse across pages, articles, and snippets.'],
            ['Understanding Sulu\'s Draft-Publish Workflow', 'sulu-draft-publish-workflow',
                '<p>Sulu separates content into draft and live dimensions — a two-stage workflow that gives editors the ability to work on content without affecting what is published, and lets AI assistants create content safely without going live until reviewed.</p>',
                'Draft Stage', '<p>Every create or update operation produces a draft. The draft is visible in the admin interface but not on the public website. Multiple saves to a draft do not produce multiple versions — each save updates the single draft state.</p>',
                'Publishing and Unpublishing', '<p>Publishing transitions the draft to the live stage and makes it visible on the website. Subsequent edits again produce a draft; the published version remains live until the next publish. Unpublishing removes the content from the public website while preserving the draft.'],
            ['Sulu\'s Block System: How to Build Flexible Content', 'sulu-block-system',
                '<p>Sulu\'s block system allows editors to compose page content from typed, reusable components — headings, text sections, images, quotes, CTAs — without touching code. For AI assistants, blocks are the primary way to add rich content to pages and articles.</p>',
                'Block Types and Properties', '<p>Each block type is defined in an XML file specifying its fields. A text block might have a single <code>content</code> field of type <code>text_editor</code>. A quote block might have a <code>text</code> field and an <code>attribution</code> field. The MCP tool <code>sulu_get_context</code> returns all available block types and their fields for a given webspace.</p>',
                'Nested Blocks', '<p>Some block types contain their own block property — a list of nested blocks. A section block, for example, has a title and a blocks property containing any number of child blocks. This allows hierarchical content structures without requiring separate content types.'],
        ];

        return \array_values(\array_map(static function(array $a) use ($blogPageUuid): array {
            [$title, $slug, $article, $h1, $p1, $p2] = $a;

            return [
                'locale' => 'en',
                'title' => $title,
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $blogPageUuid, 'path' => '/blog'],
                    'suffix' => '/' . $slug,
                ],
                'article' => $article,
                'blocks' => [
                    ['type' => 'heading', 'title' => $h1],
                    ['type' => 'text', 'content' => $p1],
                    ['type' => 'text', 'content' => $p2],
                ],
            ];
        }, $articles));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getMusicArtistArticles(string $musicPageUuid): array
    {
        return [
            [
                'locale' => 'en',
                'title' => 'David Bowie: The Chameleon of Rock',
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $musicPageUuid, 'path' => '/music'],
                    'suffix' => '/david-bowie',
                ],
                'article' => '<p>David Bowie (1947–2016) was one of the most influential musicians of the twentieth century — a relentless innovator who reinvented himself across five decades and a dozen distinct artistic personas.</p>',
                'blocks' => [
                    [
                        'type' => 'section',
                        'title' => 'Early Life and Rise to Fame',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Born David Robert Jones in Brixton, South London, Bowie showed an early aptitude for art and music. A schoolyard fight left him with a permanently dilated pupil in his left eye, giving him the distinctive mismatched eyes that would become part of his iconic look.</p>'],
                            ['type' => 'text', 'content' => '<p>After a string of failed singles in the mid-1960s, he adopted the stage name David Bowie to avoid confusion with Davy Jones of The Monkees. His breakthrough came with <em>Space Oddity</em> in 1969, timed to coincide with the Apollo 11 moon landing.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'The Ziggy Stardust Era',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p><em>The Rise and Fall of Ziggy Stardust and the Spiders from Mars</em> (1972) is widely considered one of the greatest rock albums ever made. The androgynous alien rock star Ziggy gave Bowie a vehicle to explore themes of fame, sexuality, and identity at a moment when mainstream culture was not yet ready for them.</p>'],
                            ['type' => 'quote', 'text' => '<p>I always had a repulsive need to be something more than human.</p>', 'attribution' => 'David Bowie'],
                            ['type' => 'text', 'content' => '<p>The Spiders from Mars — Mick Ronson, Trevor Bolder, and Mick Woodmansey — provided the muscular hard rock backdrop against which Bowie painted his alien mythology. Bowie killed Ziggy on stage at the Hammersmith Odeon in July 1973, leaving his band and audience blindsided.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'The Berlin Trilogy',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Fleeing addiction and Los Angeles, Bowie moved to Berlin in 1976 and collaborated with Brian Eno on a trilogy of experimental albums: <em>Low</em>, <em>Heroes</em>, and <em>Lodger</em>. These records were years ahead of their time, anticipating ambient music, post-punk, and electronic pop.</p>'],
                            ['type' => 'quote', 'text' => '<p>Heroes</p> may be the most perfect three minutes in rock history — a love song recorded in the shadow of the Berlin Wall with guitarists Mick Ronson and Robert Fripp.', 'attribution' => 'Music critic, NME'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Legacy',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Bowie released his final album, <em>Blackstar</em>, on his 69th birthday, 8 January 2016. Two days later he was dead of liver cancer. <em>Blackstar</em> was widely interpreted as a farewell, a final act of artistic control from a man who had always shaped his own narrative.</p>'],
                            ['type' => 'text', 'content' => '<p>His influence spans genres and generations: glam rock, punk, new wave, dance-pop, art rock, and contemporary artists from Lady Gaga to Kendrick Lamar cite him as essential.</p>'],
                        ],
                    ],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Nina Simone: High Priestess of Soul',
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $musicPageUuid, 'path' => '/music'],
                    'suffix' => '/nina-simone',
                ],
                'article' => '<p>Nina Simone (1933–2003) was a pianist, singer, songwriter, and civil rights activist whose music bridged jazz, blues, folk, gospel, and classical — and whose uncompromising artistry made her one of the most distinctive voices of the twentieth century.</p>',
                'blocks' => [
                    [
                        'type' => 'section',
                        'title' => 'Roots and Classical Training',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Born Eunice Kathleen Waymon in Tryon, North Carolina, she was a child prodigy who began piano lessons at age three. She trained at the Juilliard School and applied to the Curtis Institute of Music in Philadelphia, where her rejection — which she attributed to racism — redirected her life toward popular music.</p>'],
                            ['type' => 'text', 'content' => '<p>To avoid embarrassing her religious family, she took the stage name Nina Simone when she began performing in Atlantic City clubs. Her 1958 debut recording of <em>I Loves You Porgy</em> brought her immediate attention.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Music as Political Weapon',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>The 1963 bombing of the 16th Street Baptist Church in Birmingham, which killed four Black girls, radicalized Simone. She wrote <em>Mississippi Goddam</em> in an hour, describing it as her first civil rights song. The record was banned in several Southern states.</p>'],
                            ['type' => 'quote', 'text' => '<p>You can\'t help it. An artist\'s duty, as far as I\'m concerned, is to reflect the times.</p>', 'attribution' => 'Nina Simone'],
                            ['type' => 'text', 'content' => '<p>She was close friends with Langston Hughes, Lorraine Hansberry, and James Baldwin, and her music became the soundtrack of the civil rights movement alongside her activism.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Signature Songs',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Her interpretations transformed songs: she slowed Screamin\' Jay Hawkins\'s novelty number <em>I Put a Spell on You</em> into something dark and menacing; she turned the folk song <em>Sinnerman</em> into a ten-minute spiritual odyssey; and she reimagined Jacques Brel\'s <em>Ne me quitte pas</em> with devastating emotional weight.</p>'],
                            ['type' => 'heading', 'title' => 'Key Albums'],
                            ['type' => 'text', 'content' => '<p><em>Nina Simone Sings the Blues</em> (1967), <em>Silk & Soul</em> (1967), and <em>Baltimore</em> (1978) represent the breadth of her output — from raw blues to introspective folk-pop.</p>'],
                        ],
                    ],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Miles Davis: The Prince of Darkness',
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $musicPageUuid, 'path' => '/music'],
                    'suffix' => '/miles-davis',
                ],
                'article' => '<p>Miles Davis (1926–1991) stands as the defining figure of modern jazz — a trumpeter and bandleader who did not simply follow the evolution of the music but actively caused it, leading movements from bebop to cool jazz, modal jazz, jazz fusion, and beyond.</p>',
                'blocks' => [
                    [
                        'type' => 'section',
                        'title' => 'Bebop and the Birth of Cool',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Davis arrived in New York in 1944 to study at Juilliard but spent most of his time sitting in at Harlem clubs with Charlie Parker and Dizzy Gillespie, absorbing the new language of bebop. He replaced Gillespie in Parker\'s quintet at age eighteen.</p>'],
                            ['type' => 'text', 'content' => '<p>His 1949–1950 recordings with a nine-piece band (later released as <em>Birth of the Cool</em>) introduced a softer, more cerebral approach that became the foundation of West Coast cool jazz.</p>'],
                            ['type' => 'quote', 'text' => '<p>Don\'t play what\'s there, play what\'s not there.</p>', 'attribution' => 'Miles Davis'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Modal Revolution: Kind of Blue',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p><em>Kind of Blue</em> (1959) is the best-selling jazz album in history and one of the most influential recordings in any genre. By abandoning chord-based improvisation in favor of modal scales, Davis gave musicians miles of harmonic space to explore.</p>'],
                            ['type' => 'text', 'content' => '<p>The band — John Coltrane, Cannonball Adderley, Bill Evans, Paul Chambers, and Jimmy Cobb — recorded the album in two sessions with minimal rehearsal. Most of the musicians heard the compositions for the first time on the day of recording.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Electric Period and Fusion',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Davis\'s electric turn shocked the jazz establishment. <em>In a Silent Way</em> (1969) and <em>Bitches Brew</em> (1970) fused jazz improvisation with rock rhythms, electric keyboards, and studio manipulation. Many traditionalists called it a betrayal; rock audiences called it a revelation.</p>'],
                            ['type' => 'quote', 'text' => '<p>I\'ll play it first and tell you what it is later.</p>', 'attribution' => 'Miles Davis'],
                            ['type' => 'text', 'content' => '<p>Bitches Brew went platinum — something essentially unheard of for a jazz record — and its alumni (Chick Corea, Herbie Hancock, John McLaughlin, Wayne Shorter) went on to define jazz fusion.</p>'],
                        ],
                    ],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Björk: Art Pop From Another World',
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $musicPageUuid, 'path' => '/music'],
                    'suffix' => '/bjork',
                ],
                'article' => '<p>Björk Guðmundsdóttir (born 1965) is an Icelandic singer, composer, and visual artist whose work sits at the intersection of pop music, electronic experimentation, classical composition, and multimedia art. There is nothing quite like her in music history.</p>',
                'blocks' => [
                    [
                        'type' => 'section',
                        'title' => 'From The Sugarcubes to Solo Stardom',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Björk began performing professionally at age eleven in Iceland. She fronted the post-punk band The Sugarcubes in the late 1980s, which brought her international attention. Her 1993 debut solo album <em>Debut</em> announced a singular voice — intimate, operatic, and grounded in the rhythms of Reykjavík\'s nightclub scene.</p>'],
                            ['type' => 'text', 'content' => '<p><em>Post</em> (1995) and <em>Homogenic</em> (1997) pushed further into electronic production, collaborating with Nellee Hooper, Tricky, and Mark Bell to create landscapes that sounded like no one else on earth.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Orchestral and Experimental Periods',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p><em>Vespertine</em> (2001) is one of her most beloved records: intimate, microscopic, built from harp arpeggios, music boxes, and the Icelandic choir Schola Cantorum. It sounds like a love letter written in snowflakes.</p>'],
                            ['type' => 'quote', 'text' => '<p>I do not know what I am — I just know I am not like the others.</p>', 'attribution' => 'Björk'],
                            ['type' => 'text', 'content' => '<p><em>Medúlla</em> (2004) went further still — an album built almost entirely from vocal sounds, human beatboxing, and choral arrangements. <em>Biophilia</em> (2011) paired music with an app suite exploring the relationship between nature and music.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Visual Art and Multimedia',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Björk\'s art extends far beyond music. Her video collaborations with directors Michel Gondry, Spike Jonze, and Chris Cunningham are among the most imaginative in pop history. <em>Vulnicura</em> (2015) and <em>Utopia</em> (2017) came with elaborate visual worlds designed with artist James Merry.</p>'],
                            ['type' => 'text', 'content' => '<p>A 2015 retrospective at the Museum of Modern Art in New York documented her career across music, film, and installation art — a rare acknowledgement of a pop artist\'s work as fine art.</p>'],
                        ],
                    ],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Kendrick Lamar: Poet Laureate of Hip-Hop',
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $musicPageUuid, 'path' => '/music'],
                    'suffix' => '/kendrick-lamar',
                ],
                'article' => '<p>Kendrick Lamar Duckworth (born 1987) is widely considered the greatest rapper of his generation — a Pulitzer Prize-winning artist from Compton, California, whose concept albums have redefined what hip-hop can say and how it can say it.</p>',
                'blocks' => [
                    [
                        'type' => 'section',
                        'title' => 'Early Career and Good Kid, m.A.A.d City',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>Raised in Compton, Lamar began rapping at thirteen and released his debut mixtape at sixteen. After signing to Top Dawg Entertainment he released <em>Section.80</em> (2011) independently, attracting industry attention with its unflinching narrative style.</p>'],
                            ['type' => 'text', 'content' => '<p><em>Good Kid, m.A.A.d City</em> (2012) is a cinematic coming-of-age story set in Compton — a day in the life of a teenager navigating gang culture, peer pressure, and spirituality. It remains one of the most cohesive and emotionally resonant hip-hop albums ever made.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'To Pimp a Butterfly: A Political Masterwork',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p><em>To Pimp a Butterfly</em> (2015) arrived in the middle of the Black Lives Matter movement and became its unofficial soundtrack. Fusing jazz, funk, spoken word, and spoken poetry, it confronted Black identity, depression, institutional racism, and self-destruction with an ambition that had not been seen in rap since the 1990s.</p>'],
                            ['type' => 'quote', 'text' => '<p>I have a king\'s mentality when it comes to the art, but a servant\'s mentality when it comes to humanity.</p>', 'attribution' => 'Kendrick Lamar'],
                            ['type' => 'text', 'content' => '<p>The album featured contributions from Thundercat, Flying Lotus, Bilal, Anna Wise, and George Clinton, and included an unreleased verse from Tupac Shakur assembled from archival interviews.</p>'],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'title' => 'Pulitzer Prize and Cultural Impact',
                        'blocks' => [
                            ['type' => 'text', 'content' => '<p>In 2018, Lamar became the first non-classical, non-jazz artist to win the Pulitzer Prize for Music, awarded for <em>DAMN.</em> (2017). The Pulitzer board called it "a virtuosic song collection unified by its vernacular authenticity and rhythmic dynamism."</p>'],
                            ['type' => 'text', 'content' => '<p>His Super Bowl halftime show in 2025, drawing on his catalogue and his feud with Drake, became one of the most watched and discussed musical performances in recent television history.</p>'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getArticlesData(string $blogPageUuid): array
    {
        return [
            [
                'locale' => 'en',
                'title' => 'Getting Started with MCP and Sulu',
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $blogPageUuid, 'path' => '/blog'],
                    'suffix' => '/getting-started-with-mcp-and-sulu',
                ],
                'article' => '<p>A comprehensive guide to connecting AI assistants to your Sulu CMS instance via the Model Context Protocol.</p>',
                'blocks' => [
                    ['type' => 'heading', 'title' => 'What is MCP?'],
                    ['type' => 'text', 'content' => '<p>The Model Context Protocol (MCP) is an open standard that allows AI assistants to interact with external tools and data sources. By implementing MCP in Sulu, we enable AI assistants to create, read, update, and publish content — all while respecting your existing permissions and workflows.</p>'],
                    ['type' => 'heading', 'title' => 'Prerequisites'],
                    ['type' => 'text', 'content' => '<p>Before you begin, make sure you have Sulu 3.0 or later installed, PHP 8.2+, and a Sulu admin user account. The MCP bundle requires Symfony 7.3+ which is supported by Sulu 3.0.</p>'],
                    ['type' => 'heading', 'title' => 'Installation'],
                    ['type' => 'text', 'content' => '<p>Install the MCP server bundle via Composer. The bundle registers automatically and exposes an MCP endpoint at <code>/_mcp</code> by default. Configure your AI assistant to connect to this endpoint with your Sulu admin credentials.</p>'],
                    ['type' => 'quote', 'text' => '<p>MCP uses Streamable HTTP transport — no WebSocket or SSE required. It works behind load balancers and proxies out of the box.</p>', 'attribution' => 'MCP Specification'],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'AI Content Workflows: Best Practices',
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $blogPageUuid, 'path' => '/blog'],
                    'suffix' => '/ai-content-workflows-best-practices',
                ],
                'article' => '<p>How to design effective content workflows that combine human creativity with AI efficiency.</p>',
                'blocks' => [
                    ['type' => 'heading', 'title' => 'The Human-AI Content Loop'],
                    ['type' => 'text', 'content' => '<p>The most effective content workflows do not replace human editors — they augment them. AI assistants excel at drafting, formatting, and publishing content, while humans provide creative direction, brand voice, and editorial judgment.</p>'],
                    ['type' => 'quote', 'text' => '<p>Think of AI as a skilled intern: fast, tireless, and eager to help — but always needing editorial oversight and brand guidance.</p>', 'attribution' => 'Content Strategy Team'],
                    ['type' => 'heading', 'title' => 'Setting Up Content Guidelines'],
                    ['type' => 'text', 'content' => '<p>Content guidelines are the bridge between human intent and AI execution. Define your brand voice, tone, terminology, and formatting rules. The MCP server makes these guidelines available to AI assistants as context, ensuring on-brand content from the first draft.</p>'],
                    ['type' => 'heading', 'title' => 'Review and Publish Workflow'],
                    ['type' => 'text', 'content' => '<p>A solid workflow looks like this: AI creates a draft, human reviews and refines, AI publishes the approved version. Sulu\'s draft/publish workflow maps perfectly to this pattern — AI assistants can create drafts without publishing, giving editors full control over what goes live.</p>'],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Understanding Sulu Block Templates',
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $blogPageUuid, 'path' => '/blog'],
                    'suffix' => '/understanding-sulu-block-templates',
                ],
                'article' => '<p>Block templates are the building blocks of flexible content in Sulu CMS.</p>',
                'blocks' => [
                    ['type' => 'heading', 'title' => 'What Are Blocks?'],
                    ['type' => 'text', 'content' => '<p>Blocks are reusable content components that editors can arrange freely within a page. Unlike fixed templates, blocks give content creators the flexibility to compose pages from modular pieces — headings, text sections, images, quotes, and more.</p>'],
                    ['type' => 'heading', 'title' => 'Defining Block Types'],
                    ['type' => 'text', 'content' => '<p>Block types are defined in XML template files. Each type specifies its properties, validation rules, and metadata. Sulu supports shared block definitions via XML includes — define a block type once in <code>config/templates/blocks/</code> and reference it across multiple templates with <code>&lt;type ref="block_name"/&gt;</code>.</p>'],
                    ['type' => 'quote', 'text' => '<p>Shared block definitions keep your templates DRY and ensure consistency across your content types.</p>', 'attribution' => 'Sulu Documentation'],
                    ['type' => 'heading', 'title' => 'Rendering Blocks in Twig'],
                    ['type' => 'text', 'content' => '<p>In your Twig templates, iterate over blocks and include type-specific partials. Each block type gets its own template file in <code>templates/blocks/</code>, keeping rendering logic modular and maintainable.</p>'],
                ],
            ],
            [
                'locale' => 'en',
                'title' => 'Multi-Webspace Content Strategy',
                'template' => 'article',
                'url' => [
                    'page' => ['uuid' => $blogPageUuid, 'path' => '/blog'],
                    'suffix' => '/multi-webspace-content-strategy',
                ],
                'article' => '<p>Managing content across multiple webspaces and locales with AI assistance.</p>',
                'blocks' => [
                    ['type' => 'heading', 'title' => 'One CMS, Multiple Brands'],
                    ['type' => 'text', 'content' => '<p>Sulu\'s webspace architecture allows you to manage multiple websites from a single CMS installation. Each webspace can have its own templates, content guidelines, and locale configuration. The MCP server exposes all webspaces to AI assistants, letting them manage content across brands.</p>'],
                    ['type' => 'heading', 'title' => 'Locale-Aware Content Creation'],
                    ['type' => 'text', 'content' => '<p>When creating content via MCP, AI assistants must specify both the webspace and locale. The server validates these parameters against your Sulu configuration, preventing content from being created in invalid combinations.</p>'],
                    ['type' => 'text', 'content' => '<p>This validation ensures content integrity even when AI assistants are processing requests at scale. Every operation goes through the same permission checks that apply in the Sulu admin interface.</p>'],
                    ['type' => 'quote', 'text' => '<p>Security is not optional — every MCP operation uses the authenticated Sulu user\'s permissions. No privilege escalation, no shortcuts.</p>', 'attribution' => 'Security Architecture'],
                ],
            ],
        ];
    }
}
