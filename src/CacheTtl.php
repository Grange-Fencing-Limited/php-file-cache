<?php

    namespace GrangeFencing\PhpFileCache;

    enum CacheTtl: int {

        case Default      = 3600;
        case UntilCleared = 0;

    }