<?php

declare(strict_types=1);

namespace App\Domains\Download\Enums;

enum Category: string
{
    // Movies
    case Movies = '72';
    case Movie3d = '87';
    case Movie480p = '77';
    case Movie4k = '101';
    case MovieBdR = '89';
    case MovieBdRip = '90';
    case MovieCam = '96';
    case MovieDvdR = '6';
    case MovieHdBluray = '48';
    case MovieKids = '54';
    case MovieMp4 = '62';
    case MovieNonEnglish = '38';
    case MoviePacks = '68';
    case MovieWebDl = '20';
    case MovieX265 = '100';
    case MovieXvid = '7';

    // TV
    case Tv = '73';
    case Documentaries = '26';
    case Sports = '55';
    case Tv480p = '78';
    case TvBd = '23';
    case TvDvdR = '24';
    case TvDvdRip = '25';
    case TvMobile = '66';
    case TvNonEnglish = '82';
    case TvPacks = '65';
    case TvPacksNonEnglish = '83';
    case TvSdX264 = '79';
    case TvWebDl = '22';
    case TvX264 = '5';
    case TvX265 = '99';
    case TvXvid = '4';

    // Games
    case Games = '74';
    case GamesMixed = '2';
    case GamesNintendo = '47';
    case GamesPcIso = '43';
    case GamesPcRip = '45';
    case GamesPlaystation = '71';
    case GamesWii = '50';
    case GamesXbox = '44';

    // Music
    case Music = '75';
    case MusicAudio = '3';
    case MusicFlac = '80';
    case MusicPacks = '93';
    case MusicVideo = '37';
    case Podcast = '21';

    // Miscellaneous
    case Miscellaneous = '76';
    case Anime = '60';
    case Appz = '1';
    case AppzNonEnglish = '86';
    case AudioBook = '64';
    case Books = '35';
    case BooksNonEnglish = '102';
    case Comics = '94';
    case Educational = '95';
    case Fonts = '98';
    case Mac = '69';
    case MagazinesNewspapers = '92';
    case Mobile = '58';
    case PicsWallpapers = '36';

    // XXX
    case Xxx = '88';
    case XxxMagazines = '85';
    case XxxMovie = '8';
    case XxxMovie0day = '81';
    case XxxPacks = '91';
    case XxxPicsWallpapers = '84';

    public function isAdult(): bool
    {
        return str_starts_with($this->name, 'Xxx');
    }

    /**
     * @return list<self>
     */
    public static function defaults(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c): bool => ! $c->isAdult()));
    }
}
