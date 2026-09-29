<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/profile-functions.php';
require_once INCLUDES_PATH . '/friend-functions.php';
require_once INCLUDES_PATH . '/points-functions.php';


/*
|--------------------------------------------------------------------------
| Social Icon SVG
|--------------------------------------------------------------------------
*/

function profile_social_icon_svg(
    string $platform
): string {
    $path =
        match ($platform) {
        'instagram' =>
            '<path d="M352 4C296 7 248 16 207 32C166 48 130 71 100 102C69 133 46 168 31 209C15 250 6 298 3 354C2 388 1 417 0 440C0 469 0 523 0 601C0 679 0 733 1 762C2 786 3 815 4 849C7 905 16 953 32 994C48 1035 71 1070 102 1100C133 1131 168 1154 209 1169C250 1185 298 1194 354 1197C388 1198 417 1199 440 1200C470 1200 524 1200 602 1200C679 1200 733 1200 762 1199C786 1198 815 1197 849 1196C904 1193 952 1184 994 1168C1035 1152 1070 1129 1101 1098C1131 1067 1154 1032 1170 991C1185 950 1194 902 1197 846C1198 812 1199 783 1200 760C1200 730 1200 676 1200 598C1200 521 1200 467 1199 438C1198 415 1197 386 1196 352C1193 296 1184 248 1168 206C1152 165 1129 130 1099 99C1068 69 1033 46 992 30C951 15 902 6 846 3C812 2 783 1 760 0C730 0 676 0 599 0C521 0 467 0 438 1C414 2 385 3 352 4ZM359 1089C315 1087 278 1080 247 1068C221 1058 198 1043 179 1024C159 1005 144 982 133 955C121 924 114 887 112 843C111 810 110 782 109 759C108 730 108 678 108 601C108 524 108 472 109 443C109 420 110 392 111 359C113 315 120 278 132 247C142 221 157 198 177 178C196 159 219 144 245 133C276 121 313 114 357 112C390 111 418 110 441 109C469 109 522 109 599 108C676 108 728 108 757 109C780 109 808 110 842 111C886 113 923 120 953 132C979 142 1002 157 1022 176C1042 196 1057 219 1067 245C1079 276 1086 313 1088 357C1089 390 1090 419 1091 442C1092 470 1092 522 1092 599C1092 676 1092 728 1091 757C1091 780 1090 809 1089 842C1087 885 1080 922 1068 953C1058 979 1043 1002 1024 1022C1004 1042 981 1057 955 1067C924 1079 887 1086 843 1088C810 1089 782 1090 759 1091C730 1092 678 1092 602 1092C525 1092 472 1092 443 1092C420 1091 392 1090 359 1089ZM848 279C848 294 852 307 860 319C868 331 879 340 892 345C905 351 919 352 934 349C948 346 960 340 971 330C981 320 987 308 991 293C994 279 992 265 987 251C981 238 972 227 960 219C947 211 934 207 919 207C900 207 883 214 869 228C855 243 848 260 848 279ZM292 601C292 656 306 708 334 756C361 803 398 839 445 866C493 894 545 908 601 908C656 908 708 894 756 866C803 839 839 802 866 755C894 707 908 655 908 599C908 544 894 492 866 444C839 397 802 361 755 334C707 306 655 292 600 292C544 292 492 306 444 334C397 361 361 398 334 445C306 493 292 545 292 601ZM400 600C400 564 409 531 427 500C445 469 469 445 500 427C530 409 563 400 600 400C636 400 669 409 700 427C731 445 755 469 773 499C791 530 800 563 800 599C800 636 791 669 773 700C755 731 731 755 701 773C670 791 637 800 600 800C574 800 549 795 524 785C499 775 478 761 459 742C440 723 426 702 416 677C405 652 400 627 400 600Z"></path>',
        'tiktok' =>
            '<path d="M626 1C653 0 692 0 744 0H822C824 40 832 78 846 113C861 150 882 182 909 208C936 235 968 256 1007 271C1041 284 1079 293 1121 298V499C1044 496 974 480 911 451C890 441 863 425 830 404V569C830 690 830 781 829 842C827 877 820 912 809 945C798 979 782 1010 762 1039C729 1087 686 1125 633 1154C580 1183 524 1198 466 1199C431 1201 396 1197 361 1188C326 1179 293 1166 262 1148C211 1118 170 1078 137 1026C104 975 85 921 80 862C79 833 78 809 79 788C84 741 97 695 120 651C143 608 172 571 208 540C250 503 298 477 353 462C408 447 462 444 516 454C516 482 516 524 515 581C514 623 514 655 514 676C489 668 463 665 436 668C409 671 384 680 363 694C347 705 333 718 322 732C310 747 301 764 295 782C291 791 288 802 287 815C287 823 287 835 287 850L288 863C292 890 303 914 320 937C337 960 358 978 384 991C409 1004 436 1009 463 1006C490 1006 517 999 542 984C567 969 586 950 601 925L603 923C609 912 613 904 616 899C619 890 621 881 622 872C624 829 625 763 625 675V378C625 211 625 85 626 1Z"></path>',
        'facebook' =>
            '<path d="M455 1185V786H331V602H455V523C455 421 479 346 527 297C574 248 648 224 748 224C768 224 792 226 821 229C840 231 859 234 878 239V405C867 404 857 404 846 404L809 403C774 403 746 408 725 419C711 426 700 436 691 450C679 470 673 499 673 537V602H869L835 786H673V1198C771 1186 860 1152 941 1096C1021 1041 1084 970 1129 885C1176 797 1200 703 1200 602C1200 521 1184 443 1153 369C1122 297 1079 233 1024 178C969 123 905 80 834 49C759 18 681 2 600 2C519 2 441 18 366 49C295 80 231 123 176 178C121 233 78 297 47 369C16 443 0 521 0 602C0 694 20 781 60 864C99 943 153 1011 222 1067C291 1124 368 1163 455 1185Z"></path>',
        'youtube' =>
            '<path d="M1175 309C1168 283 1156 260 1137 241C1118 222 1095 209 1069 202C1041 195 987 189 906 185C848 182 780 179 701 178L600 177L499 178C420 179 352 182 294 185C213 189 159 195 131 202C105 209 83 222 64 241C45 260 32 283 25 309C18 337 12 376 7 427C4 462 2 502 1 546L0 600L1 654C2 698 4 738 7 773C12 824 18 863 25 891C32 917 45 939 64 958C83 977 105 991 131 998C159 1005 213 1011 294 1015C352 1018 420 1021 499 1022L600 1023L701 1022C780 1021 848 1018 906 1015C987 1011 1041 1005 1069 997C1095 990 1118 977 1137 958C1156 939 1168 917 1175 891C1186 852 1193 791 1197 710C1199 669 1200 633 1200 600L1199 546C1198 502 1196 462 1193 427C1188 376 1182 337 1175 309ZM791 600 477 778V422L791 600Z"></path>',
        'twitch' =>
            '<path d="M579 493V236H664V493H579ZM814 493V236H900V493H814ZM1114 0H300L86 214V986H343V1200L557 986H729L1114 600V0ZM1029 86V557L857 729H686L536 879V729H343V86H1029Z"></path>',
        'x' =>
            '<path d="M642 404 945 58H1129L727 517L1200 1142H830L540 763L208 1142H24L454 651L0 58H380L642 404ZM215 162 881 1032H982L324 162H215Z"></path>',
        'threads' =>
            '<path d="M610 1200H609C429 1199 293 1140 200 1025C117 922 75 780 74 601V600C75 420 117 279 200 176C293 60 429 1 609 0H610C748 1 862 36 951 105C1035 170 1093 261 1126 378L1024 407C997 308 948 233 879 182C809 132 719 107 609 106C463 107 354 153 282 242C215 325 180 445 179 600C180 755 215 875 282 958C354 1048 463 1093 609 1094C675 1093 731 1085 777 1069C822 1053 863 1027 899 992C925 967 944 938 956 907C966 880 971 852 970 823C969 798 963 774 954 752C939 717 911 687 872 664C863 732 841 787 808 828C763 883 701 913 621 918C591 919 562 916 534 909C506 902 481 892 458 877C432 860 411 839 396 813C381 788 372 760 371 730C369 700 374 672 386 645C397 618 414 595 437 576C459 557 485 541 516 530C547 519 580 513 617 511C667 508 717 510 767 518C761 481 749 451 730 430C705 401 665 386 612 385H611C585 385 561 389 540 398C513 409 491 426 475 451L387 392C411 356 442 328 481 309C520 290 563 280 611 280H613C692 280 755 304 801 350C847 397 872 464 877 549L893 556C930 573 962 595 989 621C1016 647 1036 676 1051 709C1066 742 1074 778 1075 816C1078 860 1071 902 1056 942C1039 989 1011 1030 974 1067C927 1112 875 1146 817 1167C758 1188 689 1199 610 1200ZM659 615C647 615 635 616 622 617C577 619 541 630 514 649C487 669 475 694 477 724C478 744 485 761 499 775C512 788 529 798 550 804C571 811 593 813 616 812C656 810 688 798 711 777C744 747 764 697 770 627C733 619 696 615 659 615Z"></path>',
        'bluesky' =>
            '<path d="M600 540C581 503 553 460 518 411C480 358 440 309 397 264C349 212 303 171 260 140C219 111 184 91 153 79C128 70 106 66 86 67C73 68 60 71 45 78C28 86 16 102 8 127C3 145 0 165 0 188C0 211 4 264 11 348C19 439 26 494 31 512C44 556 68 592 102 620C133 645 172 663 217 674C260 683 304 686 350 681L371 678L350 681C274 692 216 708 176 729C125 756 100 791 101 836C102 889 138 955 209 1034C268 1095 321 1128 369 1133C410 1137 447 1121 480 1085C507 1056 531 1013 553 958C566 926 582 880 600 821V819L612 860C628 917 642 960 653 988C672 1036 694 1071 718 1094C749 1122 784 1133 824 1127C871 1119 925 1088 987 1034C1054 967 1088 906 1089 853C1090 807 1067 768 1020 737C977 710 921 691 850 681L829 678L850 681C896 686 940 683 983 674C1028 663 1067 645 1098 620C1132 592 1156 556 1169 512C1174 494 1181 439 1189 348C1196 264 1200 211 1200 188C1200 165 1197 145 1192 127C1184 102 1172 86 1155 78C1140 71 1127 68 1114 67C1094 66 1072 70 1047 79C1016 91 981 111 940 140C897 171 851 212 803 264C760 309 720 358 682 411C647 460 619 503 600 540Z"></path>',
        'pinterest' =>
            '<path d="M599 1 601 0C520 0 442 16 368 47C296 78 232 121 177 176C122 231 79 295 49 366C17 440 1 518 1 599C1 682 18 762 51 838C82 911 127 975 185 1030C242 1086 308 1128 382 1157C374 1084 375 1027 384 986L455 688L450 676C447 667 444 656 441 645C438 630 437 614 437 599C437 572 442 547 452 524C461 502 474 485 491 472C507 459 525 453 545 453C570 453 589 461 602 478C615 493 621 513 621 537C621 552 618 571 613 594C610 607 603 629 594 659C583 694 576 720 571 737C567 756 568 774 575 791C582 808 593 821 608 830C623 840 641 845 660 845C695 845 727 833 756 810C785 787 807 755 823 714C840 671 849 624 849 571C849 524 838 481 817 444C796 407 766 379 728 358C690 337 647 327 598 327C543 328 495 341 452 366C413 389 382 421 360 461C339 500 328 542 328 587C328 612 332 638 341 663C349 688 359 709 372 725C377 730 378 735 376 742L360 810C359 815 356 819 353 820C350 821 345 821 340 818C316 807 294 789 275 762C257 738 243 710 233 678C223 647 218 616 218 586C218 521 233 462 263 408C294 351 339 307 396 275C459 240 531 223 614 223C683 223 745 238 802 268C858 299 902 340 934 392C967 445 984 504 984 569C984 638 971 700 944 757C917 814 880 859 833 892C785 926 731 943 672 943C642 943 614 936 589 922C563 909 545 893 534 874L497 1016C486 1058 461 1110 422 1173C480 1191 539 1200 599 1200C680 1200 758 1184 832 1153C904 1122 968 1079 1023 1024C1078 969 1121 906 1151 834C1183 760 1199 682 1199 601C1199 520 1183 442 1151 367C1121 296 1078 232 1023 177C968 122 904 79 832 48C758 17 680 1 599 1Z"></path>',
        'reddit' =>
            '<path d="M600 0C519 0 441 16 366 47C295 78 231 121 176 176C121 231 78 295 47 366C16 441 0 519 0 600C0 681 16 759 47 834C78 905 121 969 176 1024L61 1139C54 1146 50 1155 51 1164C51 1174 54 1182 61 1189C68 1196 76 1200 87 1200H600C681 1200 759 1184 834 1153C905 1122 969 1079 1024 1024C1079 969 1122 905 1153 834C1184 759 1200 681 1200 600C1200 519 1184 441 1153 366C1122 295 1079 231 1024 176C969 121 905 78 834 47C759 16 681 0 600 0ZM819 160C838 160 855 164 870 173C885 182 897 195 906 210C915 225 919 242 919 260C919 278 915 295 906 310C897 325 885 337 870 346C855 355 838 360 819 360C796 360 775 353 757 338C739 323 727 305 722 283C693 287 669 300 650 322C630 345 620 371 620 400C664 402 706 409 746 420C786 432 822 448 855 469C880 450 909 440 940 440C965 440 989 446 1010 458C1031 471 1048 488 1061 509C1074 531 1080 555 1080 580C1080 607 1073 632 1058 655C1043 678 1024 695 1000 706C999 759 980 809 943 855C907 899 859 934 800 960C739 987 672 1000 601 1000C529 1000 462 987 401 960C341 934 293 899 258 855C221 810 202 760 200 707C176 696 157 678 142 655C127 632 120 607 120 580C120 555 126 531 139 509C151 488 168 471 190 458C211 446 235 440 260 440C292 440 320 450 345 469C378 449 414 433 454 421C493 409 534 402 577 401V400C577 373 584 347 597 324C610 301 627 282 649 266C670 251 694 243 721 240C726 217 738 198 756 182C774 167 795 160 819 160ZM415 579C396 579 379 588 364 605C349 622 341 643 340 667C339 692 345 710 360 723C373 734 390 740 411 740C432 740 449 735 460 724C473 713 481 695 482 670C483 645 477 624 465 606C452 588 435 579 415 579ZM785 579C766 579 750 588 737 606C724 624 718 645 719 670C720 695 727 713 740 724C752 735 769 740 790 740C811 740 828 734 841 723C855 710 862 692 861 667C860 643 852 622 837 605C822 588 805 579 785 579ZM600 779C553 779 507 781 462 786C458 787 455 788 453 791C451 794 451 798 453 801C465 830 484 854 511 872C538 891 567 900 600 900C633 900 663 891 690 872C717 854 736 830 748 801C749 798 749 794 747 791C745 788 742 787 739 786C694 781 648 779 600 779Z"></path>',
        'tumblr' =>
            '<path d="M728 1200C649 1200 581 1182 524 1146C475 1115 438 1074 411 1022C388 976 377 928 377 879V487H256V332C312 312 358 281 395 240C426 207 449 167 466 120C479 85 487 48 491 9C492 6 493 4 495 2C496 1 498 0 500 0H676V306H916V487H675V861C676 893 683 919 696 939C715 966 744 979 785 979H790C805 979 823 977 842 972C861 968 876 963 887 958L944 1130C937 1141 922 1151 900 1162C878 1173 853 1181 825 1188C794 1195 765 1199 737 1200H728Z"></path>',
        'discord' =>
            '<path d="M1016 218C938 183 857 158 772 143C770 142 769 143 768 145C757 164 747 184 737 207C645 193 554 193 463 207C455 188 445 167 432 145C431 143 430 142 428 143C343 158 262 183 184 218C183 219 183 219 182 220C107 331 56 446 27 565C1 672 -6 785 5 903C5 904 6 905 7 906C56 942 106 973 158 998C205 1021 254 1041 306 1057C308 1058 309 1057 310 1056C334 1024 355 991 372 956C372 955 372 954 372 953C371 952 371 951 370 951C339 939 307 924 276 906C275 905 274 904 274 903C274 902 275 901 276 900L294 885C295 884 297 884 298 885C396 930 497 952 601 952C704 952 804 930 901 885C902 884 904 884 905 885L924 900C925 901 925 902 925 903C925 904 924 905 923 906C893 923 862 938 830 951C829 951 828 952 828 953C827 954 827 955 828 956C845 990 866 1023 889 1056C890 1057 892 1058 893 1057C945 1041 994 1021 1041 998C1094 973 1144 942 1193 906C1194 905 1195 904 1195 903C1207 776 1197 655 1166 539C1137 428 1087 322 1017 220C1017 219 1017 219 1016 218ZM401 767C382 767 364 762 347 750C330 739 317 725 308 706C298 687 293 667 293 645C293 624 298 604 308 585C317 566 330 552 347 541C363 530 381 525 401 525C421 525 439 530 456 541C473 552 486 566 495 585C504 604 509 624 509 645C509 667 504 687 495 706C485 725 472 740 456 750C439 761 421 767 401 767ZM800 767C781 767 763 762 746 750C729 739 716 725 707 706C697 687 692 667 692 645C692 624 697 604 707 585C716 566 729 552 746 541C762 530 780 525 800 525C819 525 837 530 854 541C871 552 884 566 894 585C903 604 908 624 908 645C908 667 903 687 894 706C884 725 871 740 854 750C837 761 819 767 800 767Z"></path>',
        'website' =>
            '<path d="M420 390H300C190 390 100 480 100 590S190 790 300 790H470V690H300C245 690 200 645 200 590S245 490 300 490H420V390ZM780 390H610V490H780C835 490 880 535 880 590S835 690 780 690H660V790H780C890 790 980 700 980 590S890 390 780 390ZM360 540H720V640H360V540Z"></path>',
            default =>
                '',
        };

    if ($path === '') {
        return '';
    }

    return
        '<svg viewBox="0 0 1200 1200" aria-hidden="true" focusable="false">'
        . $path
        . '</svg>';
}


/*
|--------------------------------------------------------------------------
| Requested Member
|--------------------------------------------------------------------------
*/

$targetUserReference =
    trim(
        (string) (
            $_GET['u']
            ?? ''
        )
    );

if (
    strtolower(
        $targetUserReference
    ) === 'me'
) {
    $currentProfileUserId =
        (int) (
            current_user_id()
            ?? 0
        );

    if ($currentProfileUserId > 0) {
        $targetUserReference =
            (string) $currentProfileUserId;
    }
}

if (
    $targetUserReference === ''
    || !ctype_digit(
        $targetUserReference
    )
    || (int) $targetUserReference <= 0
) {
    http_response_code(404);

    $pageTitle =
        'Profile Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested Blackthorne Academy member profile could not be found.';

    $robots =
        'noindex, nofollow';

    require
        INCLUDES_PATH
        . '/header.php';

    ?>
<main id="main-content" class="member-profile-page">
    <section class="member-profile-error">
        <div class="section-inner">
            <h1>
                Profile Not Found
            </h1>
            <p>
                The requested member profile could not be found.
            </p>
            <a class="button button-secondary" href="<?= e(url('index.php')); ?>">
                Return to Blackthorne
            </a>
        </div>
    </section>
</main>
<?php

    require
        INCLUDES_PATH
        . '/footer.php';

    exit;
}

$targetUserId =
    (int) $targetUserReference;


/*
|--------------------------------------------------------------------------
| Viewer
|--------------------------------------------------------------------------
*/

$viewerUserId =
    (int) (
        current_user_id()
        ?? 0
    );

$isOwnProfile =
    $viewerUserId > 0
    && $viewerUserId === $targetUserId;

$viewerIsStaff =
    false;

if ($viewerUserId > 0) {
    if (
        function_exists(
            'current_user_is_superuser'
        )
        && current_user_is_superuser()
    ) {
        $viewerIsStaff =
            true;

    } elseif (
        function_exists(
            'user_can_any'
        )
        && user_can_any(
            [
                'staff.dashboard.access',
                'members.admin.view',
                'members.profiles.manage',
            ]
        )
    ) {
        $viewerIsStaff =
            true;

    } elseif (
        function_exists(
            'user_can'
        )
        && (
            user_can(
                'staff.dashboard.access'
            )
            || user_can(
                'members.admin.view'
            )
            || user_can(
                'members.profiles.manage'
            )
        )
    ) {
        $viewerIsStaff =
            true;
    }
}

$viewerCanAwardHousePoints = false;
$viewerCanDeductHousePoints = false;

if ($viewerUserId > 0) {
    if (
        function_exists('current_user_is_superuser')
        && current_user_is_superuser()
    ) {
        $viewerCanAwardHousePoints = true;
        $viewerCanDeductHousePoints = true;
    } elseif (function_exists('user_can')) {
        $viewerCanAwardHousePoints =
            user_can('points.house.award');

        $viewerCanDeductHousePoints =
            user_can('points.house.deduct');
    }
}

$viewerCanEditHousePoints =
    $viewerCanAwardHousePoints
    || $viewerCanDeductHousePoints;


/*
|--------------------------------------------------------------------------
| Core Member/Profile Data
|--------------------------------------------------------------------------
*/

$memberStatement =
    $pdo->prepare(
        'SELECT
            u.id,
            u.username,
            u.display_name,
            u.avatar,
            u.status,
            u.created_at,
            u.date_of_birth,
            up.cover_image,
            up.bio,
            up.pronouns,
            up.gender,
            up.birthday_display,
            up.location,
            up.timezone,
            up.profile_visibility,
            up.show_pronouns,
            up.show_location,
            up.show_timezone,
            up.show_join_date,
            up.show_roles,
            up.show_house,
            up.show_year_group,
            up.show_activity,
            up.show_achievements,
            up.show_online_status,
            up.show_current_location,
            up.show_last_seen
         FROM users u
         LEFT JOIN user_profiles up
            ON up.user_id = u.id
         WHERE u.id = :user_id
         LIMIT 1'
    );

$memberStatement->execute([
    'user_id' =>
        $targetUserId,
]);

$member =
    $memberStatement->fetch(
        PDO::FETCH_ASSOC
    );

if (
    !$member
    || (string) $member['status'] !== 'active'
) {
    http_response_code(404);

    $pageTitle =
        'Profile Not Found | Blackthorne Academy';

    $pageDescription =
        'The requested Blackthorne Academy member profile could not be found.';

    $robots =
        'noindex, nofollow';

    require
        INCLUDES_PATH
        . '/header.php';

    ?>
<main id="main-content" class="member-profile-page">
    <section class="member-profile-error">
        <div class="section-inner">
            <h1>
                Profile Not Found
            </h1>
            <p>
                The requested member profile could not be found.
            </p>
            <a class="button button-secondary" href="<?= e(url('index.php')); ?>">
                Return to Blackthorne
            </a>
        </div>
    </section>
</main>
<?php

    require
        INCLUDES_PATH
        . '/footer.php';

    exit;
}


/*
|--------------------------------------------------------------------------
| Visibility
|--------------------------------------------------------------------------
*/

$profileVisibility =
    (string) (
        $member['profile_visibility']
        ?? 'members'
    );

$canViewProfile =
    $isOwnProfile
    || $viewerIsStaff
    || $profileVisibility === 'everyone'
    || (
        $profileVisibility === 'members'
        && $viewerUserId > 0
    );

if (!$canViewProfile) {
    http_response_code(403);

    $displayName =
        trim(
            (string) (
                $member['display_name']
                ?? $member['username']
                ?? 'Member'
            )
        );

    $pageTitle =
        'Profile Restricted | Blackthorne Academy';

    $pageDescription =
        'This Blackthorne Academy member profile is not available to your account.';

    $robots =
        'noindex, nofollow';

    require
        INCLUDES_PATH
        . '/header.php';

    ?>
<main id="main-content" class="member-profile-page">
    <section class="member-profile-error">
        <div class="section-inner">
            <p class="academy-overline">
                Member Profile
            </p>
            <h1>
                Profile Restricted
            </h1>
            <p>
                <?= e($displayName); ?> has limited who may view this profile.
            </p>

            <?php if ($viewerUserId <= 0): ?>

            <a class="button button-primary" href="<?= e(LOGIN_URL); ?>">
                Log In
            </a>

            <?php else: ?>

            <a class="button button-secondary" href="<?= e(DASHBOARD_URL); ?>">
                Back to Dashboard
            </a>

            <?php endif; ?>

        </div>
    </section>
</main>
<?php

    require
        INCLUDES_PATH
        . '/footer.php';

    exit;
}



/*
|--------------------------------------------------------------------------
| Friendship / Blocking State
|--------------------------------------------------------------------------
*/

$friendRelationshipState =
    'none';

$friendshipRow =
    null;

$viewerHasBlockedTarget =
    false;

$targetHasBlockedViewer =
    false;


if (
    $viewerUserId > 0
    && !$isOwnProfile
) {
    $viewerHasBlockedTarget =
        blackthorne_user_has_blocked(
            $pdo,
            $viewerUserId,
            $targetUserId
        );

    $targetHasBlockedViewer =
        blackthorne_user_has_blocked(
            $pdo,
            $targetUserId,
            $viewerUserId
        );

    $friendRelationship =
        blackthorne_friend_relationship(
            $pdo,
            $viewerUserId,
            $targetUserId
        );

    $friendRelationshipState =
        (string) (
            $friendRelationship['state']
            ?? 'none'
        );

    $friendshipRow =
        isset(
            $friendRelationship['friendship']
        )
        && is_array(
            $friendRelationship['friendship']
        )
            ? $friendRelationship['friendship']
            : null;
}


/*
|--------------------------------------------------------------------------
| Friend Action Flash Messages
|--------------------------------------------------------------------------
*/

$friendActionSuccess =
    get_flash(
        'success'
    );

$friendActionError =
    get_flash(
        'error'
    );


$profileReturnPath =
    'profile.php?u='
    . $targetUserId;


/*
|--------------------------------------------------------------------------
| Display Values
|--------------------------------------------------------------------------
*/

$displayName =
    trim(
        (string) (
            $member['display_name']
            ?? $member['username']
            ?? 'Member'
        )
    );

if ($displayName === '') {
    $displayName =
        'Member';
}

$username =
    trim(
        (string) (
            $member['username']
            ?? ''
        )
    );

$bio =
    trim(
        (string) (
            $member['bio']
            ?? ''
        )
    );

/*
|--------------------------------------------------------------------------
| Bio Display HTML
|--------------------------------------------------------------------------
|
| New bios are stored as sanitized rich-text HTML. Older profiles may still
| contain plain text, so both formats are handled safely here.
|
*/

if ($bio === '') {
    $bioDisplayHtml = '';
} elseif (
    preg_match(
        '/<\/?[a-z][^>]*>/i',
        $bio
    ) === 1
) {
    $bioDisplayHtml =
        sanitize_rich_text(
            $bio
        );
} else {
    $bioDisplayHtml =
        nl2br(
            e(
                $bio
            )
        );
}

$socialPlatformLabels = [
    'instagram' => 'Instagram',
    'tiktok' => 'TikTok',
    'facebook' => 'Facebook',
    'youtube' => 'YouTube',
    'twitch' => 'Twitch',
    'x' => 'X',
    'threads' => 'Threads',
    'bluesky' => 'Bluesky',
    'pinterest' => 'Pinterest',
    'reddit' => 'Reddit',
    'tumblr' => 'Tumblr',
    'discord' => 'Discord',
    'website' => 'Website',
];

$socialLinks = [];

$socialStatement =
    $pdo->prepare(
        'SELECT platform, profile_url
         FROM user_social_links
         WHERE user_id = :user_id
         ORDER BY sort_order ASC, id ASC'
    );

$socialStatement->execute([
    'user_id' => $targetUserId,
]);

foreach (
    $socialStatement->fetchAll(
        PDO::FETCH_ASSOC
    ) as $socialRow
) {
    $platform =
        (string) (
            $socialRow['platform']
            ?? ''
        );

    $socialUrl =
        trim(
            (string) (
                $socialRow['profile_url']
                ?? ''
            )
        );

    if (
        !isset(
            $socialPlatformLabels[$platform]
        )
        || !filter_var(
            $socialUrl,
            FILTER_VALIDATE_URL
        )
    ) {
        continue;
    }

    $socialLinks[] = [
        'platform' =>
            $platform,

        'label' =>
            $socialPlatformLabels[$platform],

        'url' =>
            $socialUrl,
    ];
}


$avatarSources =
    profile_picture_sources(
        isset($member['avatar'])
            ? (string) $member['avatar']
            : null
    );

$coverSources =
    profile_picture_sources(
        isset($member['cover_image'])
            ? (string) $member['cover_image']
            : null
    );

$showPronouns =
    (int) (
        $member['show_pronouns']
        ?? 1
    ) === 1;

$showLocation =
    (int) (
        $member['show_location']
        ?? 1
    ) === 1;

$showTimezone =
    (int) (
        $member['show_timezone']
        ?? 0
    ) === 1;

$showJoinDate =
    (int) (
        $member['show_join_date']
        ?? 1
    ) === 1;

$showRoles =
    (int) (
        $member['show_roles']
        ?? 1
    ) === 1;

$showHouse =
    true;

$showYearGroup =
    true;

$showActivity =
    (int) (
        $member['show_activity']
        ?? 1
    ) === 1;

$showAchievements =
    (int) (
        $member['show_achievements']
        ?? 1
    ) === 1;

$showOnlineStatus =
    (int) (
        $member['show_online_status']
        ?? 1
    ) === 1;

$showCurrentLocation =
    (int) (
        $member['show_current_location']
        ?? 1
    ) === 1;

$showLastSeen =
    (int) (
        $member['show_last_seen']
        ?? 1
    ) === 1;


/*
|--------------------------------------------------------------------------
| Roles / Groups
|--------------------------------------------------------------------------
*/

$roles = [];

$rolesStatement =
    $pdo->prepare(
        'SELECT
            r.name,
            r.display_color,
            r.grants_all_permissions
         FROM user_roles ur
         INNER JOIN roles r
            ON r.id = ur.role_id
         WHERE ur.user_id = :user_id
           AND ur.is_active = 1
           AND ur.revoked_at IS NULL
           AND (
                ur.expires_at IS NULL
                OR ur.expires_at > CURRENT_TIMESTAMP
           )
           AND r.is_active = 1
         ORDER BY
            r.grants_all_permissions DESC,
            r.sort_order ASC,
            r.name ASC'
    );

$rolesStatement->execute([
    'user_id' =>
        $targetUserId,
]);

$roles =
    $rolesStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$hasAllPermissionsRole =
    false;

foreach ($roles as $role) {
    if (
        (int) (
            $role['grants_all_permissions']
            ?? 0
        ) === 1
    ) {
        $hasAllPermissionsRole =
            true;

        break;
    }
}


/*
|--------------------------------------------------------------------------
| House
|--------------------------------------------------------------------------
*/

$house =
    null;

if (true) {
    $houseStatement =
        $pdo->prepare(
            'SELECT
                h.id,
                h.name,
                h.display_name,
                h.slug,
                h.display_color,
                h.crest_image,
                h.primary_color,
                h.secondary_color
             FROM house_memberships hm
             INNER JOIN houses h
                ON h.id = hm.house_id
             WHERE hm.user_id = :user_id
               AND hm.membership_status = \'active\'
               AND hm.left_at IS NULL
               AND h.is_active = 1
             ORDER BY
                hm.joined_at DESC,
                hm.id DESC
             LIMIT 1'
        );

    $houseStatement->execute([
        'user_id' =>
            $targetUserId,
    ]);

    $house =
        $houseStatement->fetch(
            PDO::FETCH_ASSOC
        ) ?: null;
}


$profileHouseName =
    $house !== null
        ? house_visible_name($house)
        : '';

$profileHouseColor =
    $house !== null
        ? safe_css_color(
            (string) (
                $house['display_color']
                ?? ''
            )
        )
        : null;


/*
|--------------------------------------------------------------------------
| Academic Year
|--------------------------------------------------------------------------
*/

$yearGroup =
    null;

if (true) {
    $yearStatement =
        $pdo->prepare(
            'SELECT
                yg.id,
                yg.name,
                yg.slug,
                yg.year_number,
                sye.promotion_status
             FROM student_year_enrollments sye
             INNER JOIN year_groups yg
                ON yg.id = sye.year_group_id
             WHERE sye.user_id = :user_id
               AND sye.promotion_status IN (
                    \'active\',
                    \'eligible\',
                    \'repeating\'
               )
               AND yg.is_active = 1
             ORDER BY
                sye.started_at DESC,
                sye.id DESC
             LIMIT 1'
        );

    $yearStatement->execute([
        'user_id' =>
            $targetUserId,
    ]);

    $yearGroup =
        $yearStatement->fetch(
            PDO::FETCH_ASSOC
        ) ?: null;
}


/*
|--------------------------------------------------------------------------
| Presence
|--------------------------------------------------------------------------
*/

$presence =
    null;

$presenceStatement =
    $pdo->prepare(
        'SELECT
            location_label,
            location_type,
            location_entity_id,
            last_seen_at
         FROM user_presence
         WHERE user_id = :user_id
         LIMIT 1'
    );

$presenceStatement->execute([
    'user_id' =>
        $targetUserId,
]);

$presence =
    $presenceStatement->fetch(
        PDO::FETCH_ASSOC
    ) ?: null;

$isOnline =
    false;

if (
    $presence !== null
    && !empty(
        $presence['last_seen_at']
    )
) {
    try {
        $lastSeenAt =
            new DateTimeImmutable(
                (string) $presence['last_seen_at']
            );

        $onlineCutoff =
            new DateTimeImmutable(
                '-5 minutes'
            );

        $isOnline =
            $lastSeenAt >= $onlineCutoff;

    } catch (Throwable) {
        $isOnline =
            false;
    }
}


function profile_relative_time(
    ?string $dateTime
): ?string {
    if (
        $dateTime === null
        || trim($dateTime) === ''
    ) {
        return null;
    }

    try {
        $then =
            new DateTimeImmutable(
                $dateTime
            );

        $now =
            new DateTimeImmutable();

        $seconds =
            max(
                0,
                $now->getTimestamp()
                - $then->getTimestamp()
            );

        if ($seconds < 60) {
            return 'just now';
        }

        $minutes =
            intdiv(
                $seconds,
                60
            );

        if ($minutes < 60) {
            return
                $minutes
                . ' minute'
                . (
                    $minutes === 1
                        ? ''
                        : 's'
                )
                . ' ago';
        }

        $hours =
            intdiv(
                $minutes,
                60
            );

        if ($hours < 24) {
            return
                $hours
                . ' hour'
                . (
                    $hours === 1
                        ? ''
                        : 's'
                )
                . ' ago';
        }

        $days =
            intdiv(
                $hours,
                24
            );

        if ($days < 30) {
            return
                $days
                . ' day'
                . (
                    $days === 1
                        ? ''
                        : 's'
                )
                . ' ago';
        }

        return
            $then->format(
                'M j, Y'
            );

    } catch (Throwable) {
        return null;
    }
}

$lastSeenLabel =
    $presence !== null
        ? profile_relative_time(
            isset($presence['last_seen_at'])
                ? (string) $presence['last_seen_at']
                : null
        )
        : null;


/*
|--------------------------------------------------------------------------
| Activity
|--------------------------------------------------------------------------
*/

$postCount =
    0;

$likesReceived =
    0;

if ($showActivity) {
    $postCountStatement =
        $pdo->prepare(
            'SELECT COUNT(*)
             FROM forum_posts
             WHERE user_id = :user_id
               AND is_deleted = 0'
        );

    $postCountStatement->execute([
        'user_id' =>
            $targetUserId,
    ]);

    $postCount =
        (int) $postCountStatement->fetchColumn();

    $likesStatement =
        $pdo->prepare(
            'SELECT COUNT(*)
             FROM forum_reactions fr
             INNER JOIN forum_posts fp
                ON fp.id = fr.post_id
             WHERE fp.user_id = :user_id
               AND fp.is_deleted = 0
               AND fr.reaction_type = \'like\''
        );

    $likesStatement->execute([
        'user_id' =>
            $targetUserId,
    ]);

    $likesReceived =
        (int) $likesStatement->fetchColumn();
}


/*
|--------------------------------------------------------------------------
| Achievements
|--------------------------------------------------------------------------
*/

$achievements = [];

if ($showAchievements) {
    $achievementStatement =
        $pdo->prepare(
            'SELECT
                a.name,
                a.description,
                a.badge_image,
                ua.earned_at
             FROM user_achievements ua
             INNER JOIN achievements a
                ON a.id = ua.achievement_id
             WHERE ua.user_id = :user_id
               AND a.is_active = 1
             ORDER BY
                ua.earned_at DESC,
                ua.id DESC
             LIMIT 12'
        );

    $achievementStatement->execute([
        'user_id' =>
            $targetUserId,
    ]);

    $achievements =
        $achievementStatement->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/*
|--------------------------------------------------------------------------
| Custom Profile Fields
|--------------------------------------------------------------------------
*/

$customFields = [];

$customFieldStatement =
    $pdo->prepare(
        'SELECT
            pf.field_label,
            pf.field_type,
            pfv.field_value,
            COALESCE(
                pfp.display_location,
                pf.default_display
            ) AS effective_display
         FROM profile_field_values pfv
         INNER JOIN profile_fields pf
            ON pf.id = pfv.field_id
         LEFT JOIN profile_field_preferences pfp
            ON pfp.user_id = pfv.user_id
           AND pfp.field_id = pfv.field_id
         WHERE pfv.user_id = :user_id
           AND pf.is_active = 1
           AND pf.allow_profile = 1
           AND pfv.field_value IS NOT NULL
           AND TRIM(pfv.field_value) <> \'\'
         HAVING effective_display IN (
            \'profile\',
            \'both\'
         )
         ORDER BY
            pf.sort_order ASC,
            pf.field_label ASC'
    );

$customFieldStatement->execute([
    'user_id' =>
        $targetUserId,
]);

$customFields =
    $customFieldStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Birthday / Age / Academy Identity
|--------------------------------------------------------------------------
*/

$birthdayDisplay =
    (string) (
        $member['birthday_display']
        ?? 'month_day'
    );

if (
    !in_array(
        $birthdayDisplay,
        [
            'month_day',
            'month_day_year',
            'month',
        ],
        true
    )
) {
    $birthdayDisplay =
        'month_day';
}

$birthdayLabel =
    'Unavailable';

$ageLabel =
    'Unavailable';

$dateOfBirth =
    trim(
        (string) (
            $member['date_of_birth']
            ?? ''
        )
    );

if ($dateOfBirth !== '') {
    try {
        $academyTimezone =
            new DateTimeZone(
                'America/New_York'
            );

        $birthdayDate =
            new DateTimeImmutable(
                $dateOfBirth,
                $academyTimezone
            );

        $birthdayLabel =
            match ($birthdayDisplay) {
                'month_day_year' =>
                    $birthdayDate->format(
                        'F j, Y'
                    ),

                'month' =>
                    $birthdayDate->format(
                        'F'
                    ),

                default =>
                    $birthdayDate->format(
                        'F j'
                    ),
            };

        $today =
            new DateTimeImmutable(
                'today',
                $academyTimezone
            );

        $ageLabel =
            (string) $birthdayDate
                ->diff(
                    $today
                )
                ->y;

    } catch (Throwable) {
        // Keep safe fallback labels if a historic date is malformed.
    }
}

/*
 * Current School Year point totals come from the central Points service.
 * House Points are house-only rewards, while HW Points are academic points.
 * Historical ledger entries remain scoped to their original School Year.
 */
$housePoints =
    0.0;

$homeworkPoints =
    0.0;

$currentPointsSchoolYear =
    points_current_school_year(
        $pdo
    );

if (is_array($currentPointsSchoolYear)) {
    $currentPointsSchoolYearId =
        (int) (
            $currentPointsSchoolYear['id']
            ?? 0
        );

    if ($currentPointsSchoolYearId > 0) {
        $profilePointTotals =
            points_student_totals(
                $pdo,
                $targetUserId,
                $currentPointsSchoolYearId
            );

        $housePoints =
            (float) (
                $profilePointTotals['house_only']
                ?? 0
            );

        $homeworkPoints =
            (float) (
                $profilePointTotals['academic']
                ?? 0
            );
    }
}


/*
|--------------------------------------------------------------------------
| Staff Profile House Point Editing
|--------------------------------------------------------------------------
| This profile-side workflow intentionally edits House Points only.
| HW Points remain coursework-controlled and are never editable here.
*/

$profilePointEditErrors = [];
$profilePointEditSuccess = '';
$profilePointEditOpenModal = false;

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string) ($_POST['profile_action'] ?? '') === 'change_house_points'
) {
    $profilePointEditOpenModal = true;

    if (!$viewerCanEditHousePoints) {
        $profilePointEditErrors[] = 'You do not have permission to edit House Points.';
    }

    if (!verify_csrf_token($_POST['_csrf_token'] ?? null)) {
        $profilePointEditErrors[] = 'Your form session expired. Refresh the page and try again.';
    }

    $direction = trim((string) ($_POST['direction'] ?? ''));
    $amountRaw = trim((string) ($_POST['amount'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $isPublic = isset($_POST['is_public']);

    if ($direction === 'award' && !$viewerCanAwardHousePoints) {
        $profilePointEditErrors[] = 'You do not have permission to award House Points.';
    } elseif ($direction === 'deduct' && !$viewerCanDeductHousePoints) {
        $profilePointEditErrors[] = 'You do not have permission to deduct House Points.';
    } elseif (!in_array($direction, ['award', 'deduct'], true)) {
        $profilePointEditErrors[] = 'Choose whether to award or deduct House Points.';
    }

    $pointAmount = null;

    if ($amountRaw === '' || !is_numeric($amountRaw)) {
        $profilePointEditErrors[] = 'Enter a valid House Point amount.';
    } else {
        $pointAmount = round((float) $amountRaw, 2);

        if (!is_finite($pointAmount) || $pointAmount <= 0) {
            $profilePointEditErrors[] = 'House Point amount must be greater than zero.';
        } elseif ($pointAmount > 99999999.99) {
            $profilePointEditErrors[] = 'The House Point amount is too large.';
        }
    }

    if ($description === '') {
        $profilePointEditErrors[] = 'A reason is required for every House Point change.';
    } elseif (
        (function_exists('mb_strlen') && mb_strlen($description, 'UTF-8') > 255)
        || (!function_exists('mb_strlen') && strlen($description) > 255)
    ) {
        $profilePointEditErrors[] = 'The reason must be 255 characters or fewer.';
    }

    if ($house === null) {
        $profilePointEditErrors[] = 'House Points cannot be changed until this member has an active House membership.';
    }

    if (!is_array($currentPointsSchoolYear) || $currentPointsSchoolYearId <= 0) {
        $profilePointEditErrors[] = 'There is no current active school year available for House Point changes.';
    } elseif (
        (int) ($currentPointsSchoolYear['is_active'] ?? 0) !== 1
        || (int) ($currentPointsSchoolYear['is_finalized'] ?? 0) === 1
    ) {
        $profilePointEditErrors[] = 'The current school year is not open for House Point changes.';
    }

    if ($profilePointEditErrors === [] && $pointAmount !== null) {
        try {
            $signedAmount = $direction === 'deduct'
                ? -$pointAmount
                : $pointAmount;

            $pointResult = points_award(
                $pdo,
                $targetUserId,
                'house_only',
                $direction === 'deduct' ? 'penalty' : 'manual',
                $signedAmount,
                [
                    'school_year_id' => $currentPointsSchoolYearId,
                    'description' => $description,
                    'is_public' => $isPublic,
                    'awarded_by' => $viewerUserId,
                ]
            );

            $ledger = $pointResult['ledger'] ?? null;
            $ledgerId = is_array($ledger) ? (int) ($ledger['id'] ?? 0) : 0;

            try {
                $auditStatement = $pdo->prepare(
                    'INSERT INTO audit_log (
                        user_id,
                        action_type,
                        entity_type,
                        entity_id,
                        description,
                        ip_address,
                        user_agent
                     ) VALUES (
                        :user_id,
                        :action_type,
                        :entity_type,
                        :entity_id,
                        :description,
                        :ip_address,
                        :user_agent
                     )'
                );

                $auditStatement->execute([
                    'user_id' => $viewerUserId,
                    'action_type' => $direction === 'deduct'
                        ? 'points.house.deduct'
                        : 'points.house.award',
                    'entity_type' => 'points_ledger',
                    'entity_id' => $ledgerId > 0 ? $ledgerId : $targetUserId,
                    'description' => ($direction === 'deduct' ? 'Deducted ' : 'Awarded ')
                        . number_format($pointAmount, 2)
                        . ' House Points from profile for user #'
                        . $targetUserId
                        . '. Reason: '
                        . $description,
                    'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                    'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
                ]);
            } catch (Throwable $auditException) {
                error_log('Profile House Point audit error: ' . $auditException->getMessage());
            }

            $profilePointTotals = points_student_totals(
                $pdo,
                $targetUserId,
                $currentPointsSchoolYearId
            );

            $housePoints = (float) ($profilePointTotals['house_only'] ?? 0);
            $homeworkPoints = (float) ($profilePointTotals['academic'] ?? 0);

            $profilePointEditSuccess = $direction === 'deduct'
                ? number_format($pointAmount, 2) . ' House Points deducted. The member was notified.'
                : number_format($pointAmount, 2) . ' House Points awarded. The member was notified.';

            $profilePointEditOpenModal = false;
        } catch (Throwable $pointException) {
            error_log('Profile House Point change error: ' . $pointException->getMessage());
            $profilePointEditErrors[] = 'The House Point change could not be saved: ' . $pointException->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| Profile Detail Rows
|--------------------------------------------------------------------------
*/

$detailRows = [];

$detailRows[] = [
    'label' =>
        'Birthday',

    'value' =>
        $birthdayLabel,
];

$detailRows[] = [
    'label' =>
        'Age',

    'value' =>
        $ageLabel,
];

$gender =
    trim(
        (string) (
            $member['gender']
            ?? ''
        )
    );

if ($gender !== '') {
    $detailRows[] = [
        'label' =>
            'Gender',

        'value' =>
            $gender,
    ];
}

if (
    $showPronouns
    && trim(
        (string) (
            $member['pronouns']
            ?? ''
        )
    ) !== ''
) {
    $detailRows[] = [
        'label' =>
            'Pronouns',

        'value' =>
            trim(
                (string) $member['pronouns']
            ),
    ];
}

if (
    $showLocation
    && trim(
        (string) (
            $member['location']
            ?? ''
        )
    ) !== ''
) {
    $detailRows[] = [
        'label' =>
            'Location',

        'value' =>
            trim(
                (string) $member['location']
            ),
    ];
}

if (
    $showTimezone
    && trim(
        (string) (
            $member['timezone']
            ?? ''
        )
    ) !== ''
) {
    $detailRows[] = [
        'label' =>
            'Timezone',

        'value' =>
            trim(
                (string) $member['timezone']
            ),
    ];
}

if (
    $showJoinDate
    && !empty(
        $member['created_at']
    )
) {
    try {
        $joinedAt =
            new DateTimeImmutable(
                (string) $member['created_at']
            );

        $detailRows[] = [
            'label' =>
                'Joined',

            'value' =>
                $joinedAt->format(
                    'F Y'
                ),
        ];

    } catch (Throwable) {
        // Ignore malformed historic dates rather than breaking the profile.
    }
}



/*
|--------------------------------------------------------------------------
| Friends Preview
|--------------------------------------------------------------------------
|
| The full Friends page is built separately. The profile preview intentionally
| stays small and shows no more than eight accepted friends.
|
*/

$friendCount =
    blackthorne_friend_count(
        $pdo,
        $targetUserId
    );

$friendPreview =
    $friendCount > 0
        ? blackthorne_friend_list(
            $pdo,
            $targetUserId,
            8
        )
        : [];


$friendPresenceByUserId =
    [];


if ($friendPreview !== []) {
    $friendUserIds =
        array_values(
            array_filter(
                array_map(
                    static fn (
                        array $friendPreviewMember
                    ): int =>
                        (int) (
                            $friendPreviewMember['id']
                            ?? 0
                        ),
                    $friendPreview
                ),
                static fn (
                    int $friendUserId
                ): bool =>
                    $friendUserId > 0
            )
        );

    if ($friendUserIds !== []) {
        $friendPresencePlaceholders =
            implode(
                ', ',
                array_fill(
                    0,
                    count(
                        $friendUserIds
                    ),
                    '?'
                )
            );

        $friendPresenceStatement =
            $pdo->prepare(
                'SELECT
                    u.id AS user_id,
                    COALESCE(
                        up.show_online_status,
                        1
                    ) AS show_online_status,
                    CASE
                        WHEN
                            upr.last_seen_at IS NOT NULL
                            AND upr.last_seen_at >= DATE_SUB(
                                CURRENT_TIMESTAMP,
                                INTERVAL 5 MINUTE
                            )
                        THEN 1
                        ELSE 0
                    END AS is_online

                 FROM users u

                 LEFT JOIN user_profiles up
                    ON up.user_id = u.id

                 LEFT JOIN user_presence upr
                    ON upr.user_id = u.id

                 WHERE u.id IN ('
                    . $friendPresencePlaceholders
                    . ')'
            );

        $friendPresenceStatement->execute(
            $friendUserIds
        );

        foreach (
            $friendPresenceStatement->fetchAll(
                PDO::FETCH_ASSOC
            ) as $friendPresenceRow
        ) {
            $friendPresenceByUserId[
                (int) $friendPresenceRow['user_id']
            ] = [
                'show_online_status' =>
                    (int) (
                        $friendPresenceRow[
                            'show_online_status'
                        ]
                        ?? 1
                    ) === 1,

                'is_online' =>
                    (int) (
                        $friendPresenceRow[
                            'is_online'
                        ]
                        ?? 0
                    ) === 1,
            ];
        }
    }
}


foreach (
    $friendPreview as $friendPreviewIndex =>
    $friendPreviewMember
) {
    $friendPreviewUserId =
        (int) (
            $friendPreviewMember['id']
            ?? 0
        );

    $friendPreview[
        $friendPreviewIndex
    ]['avatar_sources'] =
        profile_picture_sources(
            isset(
                $friendPreviewMember['avatar']
            )
                ? (string) $friendPreviewMember['avatar']
                : null
        );

    $friendPreview[
        $friendPreviewIndex
    ]['profile_url'] =
        url(
            'profile.php?u='
            . $friendPreviewUserId
        );

    $friendPreview[
        $friendPreviewIndex
    ]['show_online_status'] =
        (bool) (
            $friendPresenceByUserId[
                $friendPreviewUserId
            ]['show_online_status']
            ?? true
        );

    $friendPreview[
        $friendPreviewIndex
    ]['is_online'] =
        (bool) (
            $friendPresenceByUserId[
                $friendPreviewUserId
            ]['is_online']
            ?? false
        );
}


$friendsPageUrl =
    url(
        'friends.php?u='
        . $targetUserId
    );


/*
|--------------------------------------------------------------------------
| Metadata
|--------------------------------------------------------------------------
*/

$pageTitle =
    $displayName
    . ' | Blackthorne Academy';

$pageDescription =
    'View '
    . $displayName
    . '\'s Blackthorne Academy member profile.';

$pageCanonical =
    url(
        'profile.php?u='
        . $targetUserId
    );

$robots =
    'noindex, nofollow';

require
    INCLUDES_PATH
    . '/header.php';

?>

<main id="main-content" class="member-profile-page">

    <!-- ================================================================
         Profile Hero
    ================================================================= -->

    <section class="member-profile-hero<?= $coverSources['original'] !== null ? ' has-cover-image' : ''; ?>"
        aria-labelledby="member-profile-name">

        <?php if (
            $coverSources['original'] !== null
        ): ?>

        <picture class="member-profile-cover">

            <?php if (
                    $coverSources['webp'] !== null
                    && $coverSources['webp'] !== $coverSources['original']
                ): ?>

            <source srcset="<?= e($coverSources['webp']); ?>" type="image/webp">

            <?php endif; ?>

            <img src="<?= e($coverSources['original']); ?>" alt="" aria-hidden="true">

        </picture>

        <?php endif; ?>

        <div class="member-profile-hero-overlay" aria-hidden="true"></div>

        <div class="section-inner member-profile-hero-inner">

            <div class="member-profile-identity">

                <div class="member-profile-avatar">

                    <?php if (
                        $avatarSources['original'] !== null
                    ): ?>

                    <picture>

                        <?php if (
                                $avatarSources['webp'] !== null
                                && $avatarSources['webp'] !== $avatarSources['original']
                            ): ?>

                        <source srcset="<?= e($avatarSources['webp']); ?>" type="image/webp">

                        <?php endif; ?>

                        <img src="<?= e($avatarSources['original']); ?>" alt="<?= e($displayName); ?>'s avatar">

                    </picture>

                    <?php else: ?>

                    <span class="member-profile-avatar-fallback" aria-label="<?= e($displayName); ?>'s avatar">
                        <?= e(
                                profile_avatar_initial(
                                    $displayName,
                                    $username
                                )
                            ); ?>
                    </span>

                    <?php endif; ?>

                    <?php if (
                        $showOnlineStatus
                        && $isOnline
                    ): ?>

                    <span class="member-profile-online-dot" title="Online" aria-label="Online"></span>

                    <?php endif; ?>

                </div>


                <div class="member-profile-hero-copy">

                    <p class="academy-overline">
                        Blackthorne Academy Member
                    </p>

                    <h1 id="member-profile-name" <?= user_display_name_style_attr($targetUserId); ?>>
                        <?= e($displayName); ?>
                    </h1>

                    <?php if ($username !== ''): ?>

                    <p class="member-profile-username">
                        @<?= e($username); ?>
                    </p>

                    <?php endif; ?>


                    <div class="member-profile-hero-meta">

                        <?php if (
                            $hasAllPermissionsRole
                        ): ?>

                        <span class="member-profile-role-badge">
                            Admin
                        </span>

                        <?php elseif (
                            $showRoles
                            && $roles !== []
                        ): ?>

                        <?php foreach ($roles as $role): ?>

                        <span class="member-profile-role-badge" <?php if (
                                        !empty(
                                            $role['display_color']
                                        )
                                    ): ?> style="--profile-role-color: <?= e((string) $role['display_color']); ?>;"
                            <?php endif; ?>>
                            <?= e((string) $role['name']); ?>
                        </span>

                        <?php endforeach; ?>

                        <?php endif; ?>


                        <span class="member-profile-meta-item">
                            House:
                            <?php if ($profileHouseName !== ''): ?>
                            <span class="member-profile-house-name"
                                style="margin-left: 0.35rem;<?php if ($profileHouseColor !== null): ?> color: <?= e($profileHouseColor); ?>;<?php endif; ?>">
                                <?= e($profileHouseName); ?>
                            </span>
                            <?php else: ?>
                            Not Assigned
                            <?php endif; ?>
                        </span>


                        <span class="member-profile-meta-item">
                            Class Year:
                            <?= e(
                                $yearGroup !== null
                                    ? (string) $yearGroup['name']
                                    : 'Not Assigned'
                            ); ?>
                        </span>

                    </div>

                </div>


                <?php if (
                    $isOwnProfile
                    || $viewerUserId > 0
                ): ?>

                <div class="member-profile-hero-actions">

                    <?php if ($friendActionSuccess !== null): ?>

                    <p class="member-profile-action-message is-success" role="status">
                        <?= e($friendActionSuccess); ?>
                    </p>

                    <?php endif; ?>


                    <?php if ($friendActionError !== null): ?>

                    <p class="member-profile-action-message is-error" role="alert">
                        <?= e($friendActionError); ?>
                    </p>

                    <?php endif; ?>


                    <?php if ($isOwnProfile): ?>

                    <a class="button button-secondary" href="<?= e(url('profile-edit.php')); ?>">
                        Edit Profile
                    </a>

                    <a class="button button-secondary" href="<?= e(url('blocked-users.php')); ?>">
                        Blocked Members
                    </a>

                    <?php else: ?>

                    <div class="member-profile-relationship-actions">

                        <?php if ($viewerHasBlockedTarget): ?>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="unblock">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button button-secondary">
                                Unblock
                            </button>
                        </form>

                        <?php elseif ($friendRelationshipState === 'pending_outgoing'): ?>

                        <span class="button button-secondary member-profile-status-button"
                            aria-label="Friend request sent">
                            Request Sent
                        </span>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="cancel">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button button-secondary">
                                Cancel Request
                            </button>
                        </form>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="block">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button button-secondary">
                                Block
                            </button>
                        </form>

                        <?php elseif ($friendRelationshipState === 'pending_incoming'): ?>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="accept">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button">
                                Accept
                            </button>
                        </form>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="decline">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button button-secondary">
                                Decline
                            </button>
                        </form>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="block">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button button-secondary">
                                Block
                            </button>
                        </form>

                        <?php elseif ($friendRelationshipState === 'friends'): ?>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="unfriend">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button button-secondary">
                                Unfriend
                            </button>
                        </form>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="block">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button button-secondary">
                                Block
                            </button>
                        </form>

                        <?php elseif ($targetHasBlockedViewer): ?>

                        <?php
                                    /*
                                     * Intentionally render no friendship controls.
                                     *
                                     * This avoids revealing to the viewer that the
                                     * other member has blocked them.
                                     */
                                    ?>

                        <?php else: ?>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="add">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button">
                                Add Friend
                            </button>
                        </form>

                        <form method="post" action="<?= e(url('friend-action.php')); ?>"
                            class="member-profile-action-form">
                            <?= csrf_field(); ?>

                            <input type="hidden" name="action" value="block">

                            <input type="hidden" name="target_user_id" value="<?= (int) $targetUserId; ?>">

                            <input type="hidden" name="return_to" value="<?= e($profileReturnPath); ?>">

                            <button type="submit" class="button button-secondary">
                                Block
                            </button>
                        </form>

                        <?php endif; ?>

                    </div>

                    <?php endif; ?>

                </div>

                <?php endif; ?>

            </div>

        </div>

    </section>


    <!-- ================================================================
         Main Profile Layout
    ================================================================= -->

    <section class="member-profile-content">

        <div class="section-inner member-profile-grid">


            <!-- ========================================================
                 Sidebar
            ========================================================= -->

            <aside class="member-profile-sidebar">

                <section class="member-profile-card">

                    <header class="member-profile-card-heading">

                        <p class="academy-overline">
                            Member Details
                        </p>

                        <h2>
                            About
                        </h2>

                    </header>


                    <?php if ($detailRows !== []): ?>

                    <dl class="member-profile-detail-list">

                        <?php foreach ($detailRows as $detailRow): ?>

                        <div class="member-profile-detail-row">

                            <dt>
                                <?= e((string) $detailRow['label']); ?>
                            </dt>

                            <dd>
                                <?= e((string) $detailRow['value']); ?>
                            </dd>

                        </div>

                        <?php endforeach; ?>

                    </dl>

                    <?php else: ?>

                    <p class="member-profile-empty">
                        No additional profile details are being shown.
                    </p>

                    <?php endif; ?>

                </section>


                <section class="member-profile-card member-profile-friends-card" id="friends">

                    <header class="member-profile-card-heading member-profile-friends-heading">

                        <div>

                            <p class="academy-overline">
                                Community
                            </p>

                            <h2>
                                Friends
                            </h2>

                        </div>


                        <span class="member-profile-friend-count"
                            aria-label="<?= number_format($friendCount); ?> friends">
                            <?= number_format($friendCount); ?>
                        </span>

                    </header>


                    <?php if ($friendPreview !== []): ?>

                    <div class="member-profile-friend-preview">

                        <?php foreach ($friendPreview as $friendPreviewMember): ?>

                        <?php
                                $friendAvatarSources =
                                    is_array(
                                        $friendPreviewMember['avatar_sources']
                                        ?? null
                                    )
                                        ? $friendPreviewMember['avatar_sources']
                                        : [
                                            'original' => null,
                                            'webp' => null,
                                        ];

                                $friendDisplayName =
                                    trim(
                                        (string) (
                                            $friendPreviewMember['display_name']
                                            ?? ''
                                        )
                                    );

                                if ($friendDisplayName === '') {
                                    $friendDisplayName =
                                        trim(
                                            (string) (
                                                $friendPreviewMember['username']
                                                ?? 'Member'
                                            )
                                        );
                                }
                                ?>

                        <a class="member-profile-friend" href="<?= e((string) $friendPreviewMember['profile_url']); ?>"
                            title="<?= e($friendDisplayName); ?>"
                            aria-label="View <?= e($friendDisplayName); ?>'s profile">

                            <span class="member-profile-friend-avatar">

                                <?php if (
                                            $friendAvatarSources['original']
                                            !== null
                                        ): ?>

                                <picture>

                                    <?php if (
                                                    $friendAvatarSources['webp']
                                                    !== null
                                                    && $friendAvatarSources['webp']
                                                        !== $friendAvatarSources['original']
                                                ): ?>

                                    <source srcset="<?= e($friendAvatarSources['webp']); ?>" type="image/webp">

                                    <?php endif; ?>

                                    <img src="<?= e($friendAvatarSources['original']); ?>" alt="" loading="lazy"
                                        decoding="async">

                                </picture>

                                <?php else: ?>

                                <span class="member-profile-friend-avatar-fallback" aria-hidden="true">
                                    <?= e(
                                                    profile_avatar_initial(
                                                        $friendDisplayName
                                                    )
                                                ); ?>
                                </span>

                                <?php endif; ?>


                                <?php if (
                                            (bool) (
                                                $friendPreviewMember[
                                                    'show_online_status'
                                                ]
                                                ?? true
                                            )
                                        ): ?>

                                <?php
                                            $friendIsOnline =
                                                (bool) (
                                                    $friendPreviewMember[
                                                        'is_online'
                                                    ]
                                                    ?? false
                                                );
                                            ?>

                                <span class="member-profile-friend-presence<?= $friendIsOnline ? ' is-online' : ''; ?>"
                                    title="<?= $friendIsOnline ? 'Online' : 'Offline'; ?>"
                                    aria-label="<?= $friendIsOnline ? 'Online' : 'Offline'; ?>"></span>

                                <?php endif; ?>

                            </span>


                            <span class="member-profile-friend-name">
                                <?= e($friendDisplayName); ?>
                            </span>

                        </a>

                        <?php endforeach; ?>

                    </div>


                    <a class="member-profile-friends-view-all" href="<?= e($friendsPageUrl); ?>">
                        View All Friends
                    </a>

                    <?php else: ?>

                    <p class="member-profile-empty">
                        <?= e($displayName); ?> has not added any friends yet.
                    </p>

                    <?php endif; ?>

                </section>


                <?php if ($socialLinks !== []): ?>

                <section class="member-profile-card member-profile-social-card">

                    <header class="member-profile-card-heading">

                        <p class="academy-overline">
                            Connect
                        </p>

                        <h2>
                            Around the Web
                        </h2>

                    </header>


                    <div class="member-profile-social-links">

                        <?php foreach ($socialLinks as $socialLink): ?>

                        <a class="member-profile-social-link" href="<?= e((string) $socialLink['url']); ?>"
                            target="_blank" rel="noopener noreferrer nofollow"
                            aria-label="<?= e((string) $socialLink['label']); ?>"
                            title="<?= e((string) $socialLink['label']); ?>">
                            <?= profile_social_icon_svg(
                                        (string) $socialLink['platform']
                                    ); ?>
                            <span class="sr-only">
                                <?= e((string) $socialLink['label']); ?>
                            </span>
                        </a>

                        <?php endforeach; ?>

                    </div>

                </section>

                <?php endif; ?>


                <?php if (
                    $showOnlineStatus
                    || $showCurrentLocation
                    || $showLastSeen
                ): ?>

                <section class="member-profile-card">

                    <header class="member-profile-card-heading">

                        <p class="academy-overline">
                            Presence
                        </p>

                        <h2>
                            Around the Academy
                        </h2>

                    </header>


                    <div class="member-profile-presence">

                        <?php if ($showOnlineStatus): ?>

                        <div class="member-profile-presence-row">

                            <span class="member-profile-presence-status<?= $isOnline ? ' is-online' : ''; ?>"
                                aria-hidden="true"></span>

                            <span>
                                <?= $isOnline ? 'Online' : 'Offline'; ?>
                            </span>

                        </div>

                        <?php endif; ?>


                        <?php if (
                                $showCurrentLocation
                                && $isOnline
                                && $presence !== null
                                && trim(
                                    (string) (
                                        $presence['location_label']
                                        ?? ''
                                    )
                                ) !== ''
                            ): ?>

                        <div class="member-profile-presence-copy">

                            <span class="member-profile-small-label">
                                Browsing
                            </span>

                            <strong>
                                <?= e(
                                            trim(
                                                (string) $presence['location_label']
                                            )
                                        ); ?>
                            </strong>

                        </div>

                        <?php endif; ?>


                        <?php if (
                                $showLastSeen
                                && !$isOnline
                                && $lastSeenLabel !== null
                            ): ?>

                        <div class="member-profile-presence-copy">

                            <span class="member-profile-small-label">
                                Last Seen
                            </span>

                            <strong>
                                <?= e($lastSeenLabel); ?>
                            </strong>

                        </div>

                        <?php endif; ?>

                    </div>

                </section>

                <?php endif; ?>


                <?php if (
                    $showActivity
                ): ?>

                <section class="member-profile-card">

                    <header class="member-profile-card-heading">

                        <p class="academy-overline">
                            Community
                        </p>

                        <h2>
                            Activity
                        </h2>

                    </header>


                    <div class="member-profile-stats">

                        <div class="member-profile-stat">

                            <strong>
                                <?= number_format($postCount); ?>
                            </strong>

                            <span>
                                Posts
                            </span>

                        </div>


                        <div class="member-profile-stat">

                            <strong>
                                <?= number_format($likesReceived); ?>
                            </strong>

                            <span>
                                Likes
                            </span>

                        </div>

                    </div>

                </section>

                <?php endif; ?>

            </aside>


            <!-- ========================================================
                 Main Column
            ========================================================= -->

            <div class="member-profile-main">


                <section class="member-profile-academy-points" aria-label="Academy points">

                    <div class="member-profile-academy-point">

                        <span>
                            House Points
                        </span>

                        <strong>
                            <?= number_format($housePoints); ?>
                        </strong>

                        <?php if ($viewerCanEditHousePoints): ?>

                        <button class="button button-secondary js-profile-points-modal-open" type="button"
                            style="margin-top:10px;padding:7px 12px;font-size:.82rem;">
                            Edit House Points
                        </button>

                        <?php endif; ?>

                    </div>


                    <div class="member-profile-academy-point">

                        <span>
                            HW Points
                        </span>

                        <strong>
                            <?= number_format($homeworkPoints); ?>
                        </strong>

                    </div>

                </section>

                <?php if ($profilePointEditSuccess !== ''): ?>
                <div class="member-profile-action-message is-success" role="status" style="margin:0 0 18px;">
                    <?= e($profilePointEditSuccess); ?>
                </div>
                <?php endif; ?>

                <?php if ($viewerCanEditHousePoints): ?>
                <style>
                    .profile-points-modal[hidden] {
                        display: none !important;
                    }

                    .profile-points-modal {
                        position: fixed;
                        inset: 0;
                        z-index: 10020;
                        display: grid;
                        place-items: center;
                        padding: 24px;
                    }

                    .profile-points-modal-backdrop {
                        position: absolute;
                        inset: 0;
                        border: 0;
                        background: rgba(7, 4, 10, .82);
                        cursor: pointer;
                    }

                    .profile-points-modal-card {
                        position: relative;
                        z-index: 1;
                        width: min(560px, 100%);
                        max-height: calc(100vh - 48px);
                        overflow-y: auto;
                        padding: 30px;
                        border: 1px solid rgba(201, 168, 92, .52);
                        border-radius: 18px;
                        background: linear-gradient(145deg, rgba(35, 16, 40, .99), rgba(12, 8, 15, .99));
                        box-shadow: 0 24px 70px rgba(0, 0, 0, .58), inset 0 0 0 1px rgba(255, 255, 255, .025);
                    }

                    .profile-points-modal-card::before {
                        content: '';
                        position: absolute;
                        inset: 8px;
                        border: 1px solid rgba(201, 168, 92, .18);
                        border-radius: 12px;
                        pointer-events: none;
                    }

                    .profile-points-modal-close {
                        position: absolute;
                        top: 14px;
                        right: 14px;
                        z-index: 2;
                        width: 38px;
                        height: 38px;
                        border: 1px solid rgba(201, 168, 92, .34);
                        border-radius: 50%;
                        background: rgba(10, 6, 12, .78);
                        color: #d7bd7a;
                        font-size: 1.35rem;
                        cursor: pointer;
                    }

                    .profile-points-modal-title {
                        margin: 3px 48px 8px 0;
                        font-family: Georgia, 'Times New Roman', serif;
                        color: #d7bd7a;
                        font-size: clamp(1.5rem, 4vw, 2rem);
                        font-weight: 400;
                    }

                    .profile-points-modal-copy {
                        margin: 0 0 22px;
                        color: rgba(245, 239, 229, .76);
                    }

                    .profile-points-modal-meta {
                        display: flex;
                        gap: 10px;
                        flex-wrap: wrap;
                        margin: 0 0 22px;
                    }

                    .profile-points-modal-pill {
                        padding: 7px 11px;
                        border: 1px solid rgba(201, 168, 92, .25);
                        border-radius: 999px;
                        background: rgba(255, 255, 255, .035);
                        color: rgba(245, 239, 229, .82);
                        font-size: .84rem;
                    }

                    .profile-points-modal .form-group {
                        margin-bottom: 17px;
                    }

                    .profile-points-modal .form-group label {
                        display: block;
                        margin-bottom: 7px;
                    }

                    .profile-points-modal-actions {
                        display: flex;
                        gap: 10px;
                        flex-wrap: wrap;
                        margin-top: 22px;
                    }

                    body.profile-points-modal-open {
                        overflow: hidden;
                    }

                    @media (max-width: 600px) {
                        .profile-points-modal {
                            padding: 14px;
                        }

                        .profile-points-modal-card {
                            padding: 24px 20px;
                        }
                    }

                </style>

                <div class="profile-points-modal" id="profile-points-modal" role="dialog" aria-modal="true"
                    aria-labelledby="profile-points-modal-title" <?= $profilePointEditOpenModal ? '' : 'hidden'; ?>>
                    <button class="profile-points-modal-backdrop js-profile-points-modal-close" type="button"
                        aria-label="Close House Point editor"></button>

                    <div class="profile-points-modal-card">
                        <button class="profile-points-modal-close js-profile-points-modal-close" type="button"
                            aria-label="Close">×</button>
                        <p class="academy-overline">Staff Point Control</p>
                        <h3 class="profile-points-modal-title" id="profile-points-modal-title">Edit
                            <?= e($displayName); ?>'s House Points</h3>
                        <p class="profile-points-modal-copy">This changes House Points only. HW Points remain controlled
                            by coursework and cannot be edited from a member profile.</p>

                        <div class="profile-points-modal-meta">
                            <span class="profile-points-modal-pill">Current House Points:
                                <?= number_format($housePoints); ?></span>
                            <?php if (is_array($currentPointsSchoolYear)): ?>
                            <span
                                class="profile-points-modal-pill"><?= e((string) ($currentPointsSchoolYear['name'] ?? 'Current School Year')); ?></span>
                            <?php endif; ?>
                            <?php if ($profileHouseName !== ''): ?>
                            <span class="profile-points-modal-pill"><?= e($profileHouseName); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ($profilePointEditErrors !== []): ?>
                        <div class="member-profile-action-message is-error" role="alert" style="margin-bottom:18px;">
                            <?php foreach ($profilePointEditErrors as $profilePointError): ?>
                            <div><?= e($profilePointError); ?></div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($house === null): ?>
                        <div class="member-profile-action-message is-error" role="status">This member must be sorted
                            into a House before House Points can be changed.</div>
                        <?php elseif (!is_array($currentPointsSchoolYear) || $currentPointsSchoolYearId <= 0): ?>
                        <div class="member-profile-action-message is-error" role="status">No current active school year
                            is available.</div>
                        <?php elseif ((int) ($currentPointsSchoolYear['is_finalized'] ?? 0) === 1): ?>
                        <div class="member-profile-action-message is-error" role="status">The current school year has
                            been finalized and its point records are read-only.</div>
                        <?php else: ?>
                        <form method="post" action="<?= e(url('profile.php?u=' . $targetUserId)); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="profile_action" value="change_house_points">

                            <div class="form-group">
                                <label for="profile-points-direction">Change</label>
                                <select class="form-control" id="profile-points-direction" name="direction" required>
                                    <?php if ($viewerCanAwardHousePoints): ?><option value="award"
                                        <?= (($_POST['direction'] ?? '') === 'award') ? ' selected' : ''; ?>>Award House
                                        Points</option><?php endif; ?>
                                    <?php if ($viewerCanDeductHousePoints): ?><option value="deduct"
                                        <?= (($_POST['direction'] ?? '') === 'deduct') ? ' selected' : ''; ?>>Deduct
                                        House Points</option><?php endif; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="profile-points-amount">Amount</label>
                                <input class="form-control" id="profile-points-amount" name="amount" type="number"
                                    min="0.01" max="99999999.99" step="0.01"
                                    value="<?= e((string) ($_POST['amount'] ?? '')); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="profile-points-description">Reason</label>
                                <input class="form-control" id="profile-points-description" name="description"
                                    type="text" maxlength="255"
                                    value="<?= e((string) ($_POST['description'] ?? '')); ?>"
                                    placeholder="Why are these House Points changing?" required>
                            </div>

                            <div class="form-group">
                                <label style="display:flex;align-items:center;gap:9px;">
                                    <input type="checkbox" name="is_public" value="1"
                                        <?= isset($_POST['is_public']) ? ' checked' : ''; ?>>
                                    Show this change in the public House Point feed
                                </label>
                            </div>

                            <div class="profile-points-modal-actions">
                                <button class="button button-primary" type="submit">Save House Point Change</button>
                                <button class="button button-secondary js-profile-points-modal-close"
                                    type="button">Cancel</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                    (function() {
                        const modal = document.getElementById('profile-points-modal');
                        if (!modal) return;

                        let lastTrigger = null;
                        const openers = document.querySelectorAll('.js-profile-points-modal-open');
                        const closers = modal.querySelectorAll('.js-profile-points-modal-close');

                        const openModal = function(trigger) {
                            lastTrigger = trigger || document.activeElement;
                            modal.hidden = false;
                            document.body.classList.add('profile-points-modal-open');
                            const field = modal.querySelector('select, input:not([type="hidden"]), button');
                            if (field) window.setTimeout(() => field.focus(), 0);
                        };

                        const closeModal = function() {
                            modal.hidden = true;
                            document.body.classList.remove('profile-points-modal-open');
                            if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
                        };

                        openers.forEach((button) => button.addEventListener('click', () => openModal(button)));
                        closers.forEach((button) => button.addEventListener('click', closeModal));

                        document.addEventListener('keydown', function(event) {
                            if (event.key === 'Escape' && !modal.hidden) closeModal();
                        });

                        if (!modal.hidden) {
                            document.body.classList.add('profile-points-modal-open');
                        }
                    }());

                </script>
                <?php endif; ?>


                <section class="member-profile-card member-profile-card-large">

                    <header class="member-profile-card-heading">

                        <p class="academy-overline">
                            Introduction
                        </p>

                        <h2>
                            About <?= e($displayName); ?>
                        </h2>

                    </header>


                    <?php if ($bioDisplayHtml !== ''): ?>

                    <div class="member-profile-bio forum-rich-text">
                        <?= $bioDisplayHtml; ?>
                    </div>

                    <?php else: ?>

                    <p class="member-profile-empty">
                        <?= e($displayName); ?> has not added a bio yet.
                    </p>

                    <?php endif; ?>

                </section>


                <?php if (
                    $customFields !== []
                ): ?>

                <section class="member-profile-card member-profile-card-large">

                    <header class="member-profile-card-heading">

                        <p class="academy-overline">
                            Profile Details
                        </p>

                        <h2>
                            More About Me
                        </h2>

                    </header>


                    <dl class="member-profile-custom-fields">

                        <?php foreach ($customFields as $customField): ?>

                        <div class="member-profile-custom-field">

                            <dt>
                                <?= e((string) $customField['field_label']); ?>
                            </dt>

                            <dd>

                                <?php
                                        $fieldValue =
                                            trim(
                                                (string) $customField['field_value']
                                            );

                                        $fieldType =
                                            (string) $customField['field_type'];
                                        ?>

                                <?php if (
                                            $fieldType === 'url'
                                            && filter_var(
                                                $fieldValue,
                                                FILTER_VALIDATE_URL
                                            ) !== false
                                            && preg_match(
                                                '#^https?://#i',
                                                $fieldValue
                                            ) === 1
                                        ): ?>

                                <a href="<?= e($fieldValue); ?>" target="_blank" rel="noopener noreferrer">
                                    <?= e($fieldValue); ?>
                                </a>

                                <?php elseif (
                                            $fieldType === 'textarea'
                                        ): ?>

                                <?= nl2br(e($fieldValue)); ?>

                                <?php else: ?>

                                <?= e($fieldValue); ?>

                                <?php endif; ?>

                            </dd>

                        </div>

                        <?php endforeach; ?>

                    </dl>

                </section>

                <?php endif; ?>


                <?php if (
                    $showAchievements
                ): ?>

                <section class="member-profile-card member-profile-card-large">

                    <header class="member-profile-card-heading">

                        <p class="academy-overline">
                            Academy Record
                        </p>

                        <h2>
                            Achievements
                        </h2>

                    </header>


                    <?php if ($achievements !== []): ?>

                    <style>
                        .member-profile-achievements {
                            display: flex;
                            flex-wrap: wrap;
                            gap: 18px;
                            align-items: center;
                        }

                        .member-profile-achievement-button {
                            display: block;
                            width: 112px;
                            height: 112px;
                            padding: 6px;
                            border: 1px solid rgba(197, 154, 75, .28);
                            border-radius: 16px;
                            background: rgba(12, 5, 17, .42);
                            cursor: pointer;
                            transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
                        }

                        .member-profile-achievement-button:hover,
                        .member-profile-achievement-button:focus-visible {
                            transform: translateY(-2px) scale(1.03);
                            border-color: rgba(219, 178, 92, .72);
                            box-shadow: 0 10px 24px rgba(0, 0, 0, .28);
                            outline: none;
                        }

                        .member-profile-achievement-badge,
                        .member-profile-achievement-mark {
                            width: 100px;
                            height: 100px;
                        }

                        .member-profile-achievement-badge {
                            display: block;
                            object-fit: contain;
                        }

                        .member-profile-achievement-mark {
                            display: grid;
                            place-items: center;
                            font-size: 2.15rem;
                        }

                        .achievement-modal[hidden] {
                            display: none;
                        }

                        .achievement-modal {
                            position: fixed;
                            inset: 0;
                            z-index: 9999;
                            display: grid;
                            place-items: center;
                            padding: 24px;
                        }

                        .achievement-modal-backdrop {
                            position: absolute;
                            inset: 0;
                            border: 0;
                            background: rgba(4, 1, 7, .82);
                            backdrop-filter: blur(5px);
                            cursor: default;
                        }

                        .achievement-modal-card {
                            position: relative;
                            width: min(92vw, 520px);
                            padding: 34px 30px 30px;
                            border: 1px solid rgba(209, 166, 80, .48);
                            border-radius: 20px;
                            background:
                                radial-gradient(circle at top, rgba(91, 44, 91, .24), transparent 42%),
                                linear-gradient(180deg, #1a0d20 0%, #100813 100%);
                            box-shadow: 0 28px 70px rgba(0, 0, 0, .58);
                            text-align: center;
                        }

                        .achievement-modal-close {
                            position: absolute;
                            top: 12px;
                            right: 14px;
                            width: 38px;
                            height: 38px;
                            border: 1px solid rgba(209, 166, 80, .3);
                            border-radius: 50%;
                            background: rgba(7, 3, 10, .7);
                            color: #e4c779;
                            font-size: 1.45rem;
                            line-height: 1;
                            cursor: pointer;
                        }

                        .achievement-modal-image {
                            display: block;
                            width: min(240px, 62vw);
                            height: min(240px, 62vw);
                            margin: 8px auto 24px;
                            object-fit: contain;
                            filter: drop-shadow(0 12px 24px rgba(0, 0, 0, .45));
                        }

                        .achievement-modal-kicker {
                            margin: 0 0 8px;
                            color: #d6ad55;
                            font-size: .76rem;
                            font-weight: 700;
                            letter-spacing: .18em;
                            text-transform: uppercase;
                        }

                        .achievement-modal-title {
                            margin: 0;
                            color: #f0d477;
                            font-family: Georgia, 'Times New Roman', serif;
                            font-size: clamp(2rem, 7vw, 3rem);
                            line-height: 1.05;
                        }

                        .achievement-modal-divider {
                            width: 86px;
                            height: 1px;
                            margin: 18px auto;
                            background: linear-gradient(90deg, transparent, #c79c45, transparent);
                        }

                        .achievement-modal-description {
                            margin: 0 auto;
                            max-width: 42ch;
                            color: #d9cddd;
                            font-size: 1rem;
                            line-height: 1.65;
                        }

                        .achievement-modal-earned {
                            margin: 18px 0 0;
                            color: #a995aa;
                            font-size: .84rem;
                            letter-spacing: .04em;
                        }

                        @media (max-width: 520px) {
                            .member-profile-achievement-button {
                                width: 104px;
                                height: 104px;
                            }

                            .member-profile-achievement-badge,
                            .member-profile-achievement-mark {
                                width: 92px;
                                height: 92px;
                            }

                            .achievement-modal-card {
                                padding: 30px 20px 24px;
                            }
                        }

                    </style>

                    <div class="member-profile-achievements">

                        <?php foreach ($achievements as $achievement): ?>

                        <?php
                                    $achievementName =
                                        trim((string) ($achievement['name'] ?? ''));

                                    $achievementDescription =
                                        trim((string) ($achievement['description'] ?? ''));

                                    $achievementBadgePath =
                                        trim((string) ($achievement['badge_image'] ?? ''));

                                    $achievementBadgeUrl = '';

                                    if ($achievementBadgePath !== '') {
                                        $achievementBadgeUrl =
                                            preg_match('#^https?://#i', $achievementBadgePath) === 1
                                                ? $achievementBadgePath
                                                : url(ltrim($achievementBadgePath, '/'));
                                    }

                                    $achievementEarnedAt =
                                        trim((string) ($achievement['earned_at'] ?? ''));

                                    $achievementEarnedLabel = '';

                                    if ($achievementEarnedAt !== '') {
                                        try {
                                            $achievementEarnedLabel =
                                                (new DateTimeImmutable($achievementEarnedAt))
                                                    ->format('F j, Y');
                                        } catch (Throwable $exception) {
                                            $achievementEarnedLabel = '';
                                        }
                                    }
                                    ?>

                        <button type="button" class="member-profile-achievement-button js-achievement-modal-open"
                            aria-label="View <?= e($achievementName); ?> achievement"
                            data-achievement-name="<?= e($achievementName); ?>"
                            data-achievement-description="<?= e($achievementDescription); ?>"
                            data-achievement-image="<?= e($achievementBadgeUrl); ?>"
                            data-achievement-earned="<?= e($achievementEarnedLabel); ?>">

                            <?php if ($achievementBadgeUrl !== ''): ?>

                            <img class="member-profile-achievement-badge" src="<?= e($achievementBadgeUrl); ?>"
                                alt="<?= e($achievementName); ?> badge" width="100" height="100" loading="lazy">

                            <?php else: ?>

                            <span class="member-profile-achievement-mark" aria-hidden="true">
                                ✦
                            </span>

                            <?php endif; ?>

                        </button>

                        <?php endforeach; ?>

                    </div>

                    <div class="achievement-modal" id="achievement-modal" role="dialog" aria-modal="true"
                        aria-labelledby="achievement-modal-title" hidden>
                        <button type="button" class="achievement-modal-backdrop js-achievement-modal-close"
                            aria-label="Close achievement details"></button>

                        <div class="achievement-modal-card">
                            <button type="button" class="achievement-modal-close js-achievement-modal-close"
                                aria-label="Close achievement details">
                                ×
                            </button>

                            <img class="achievement-modal-image" id="achievement-modal-image" src="" alt="" width="240"
                                height="240">

                            <p class="achievement-modal-kicker">Achievement Earned</p>
                            <h3 class="achievement-modal-title" id="achievement-modal-title"></h3>
                            <div class="achievement-modal-divider" aria-hidden="true"></div>
                            <p class="achievement-modal-description" id="achievement-modal-description"></p>
                            <p class="achievement-modal-earned" id="achievement-modal-earned"></p>
                        </div>
                    </div>

                    <script>
                        (() => {
                            const modal = document.getElementById('achievement-modal');

                            if (!modal) {
                                return;
                            }

                            const image = document.getElementById('achievement-modal-image');
                            const title = document.getElementById('achievement-modal-title');
                            const description = document.getElementById('achievement-modal-description');
                            const earned = document.getElementById('achievement-modal-earned');
                            let lastTrigger = null;

                            const closeModal = () => {
                                modal.hidden = true;
                                document.body.style.overflow = '';

                                if (lastTrigger) {
                                    lastTrigger.focus();
                                }
                            };

                            document.querySelectorAll('.js-achievement-modal-open').forEach((button) => {
                                button.addEventListener('click', () => {
                                    lastTrigger = button;

                                    const name = button.dataset.achievementName || 'Achievement';
                                    const details = button.dataset.achievementDescription || '';
                                    const imageUrl = button.dataset.achievementImage || '';
                                    const earnedLabel = button.dataset.achievementEarned || '';

                                    title.textContent = name;
                                    description.textContent = details;
                                    description.hidden = details === '';

                                    if (imageUrl !== '') {
                                        image.src = imageUrl;
                                        image.alt = `${name} badge`;
                                        image.hidden = false;
                                    } else {
                                        image.src = '';
                                        image.alt = '';
                                        image.hidden = true;
                                    }

                                    earned.textContent = earnedLabel !== '' ?
                                        `Earned ${earnedLabel}` :
                                        '';
                                    earned.hidden = earnedLabel === '';

                                    modal.hidden = false;
                                    document.body.style.overflow = 'hidden';

                                    const closeButton = modal.querySelector(
                                        '.achievement-modal-close');
                                    if (closeButton) {
                                        closeButton.focus();
                                    }
                                });
                            });

                            modal.querySelectorAll('.js-achievement-modal-close').forEach((button) => {
                                button.addEventListener('click', closeModal);
                            });

                            document.addEventListener('keydown', (event) => {
                                if (event.key === 'Escape' && !modal.hidden) {
                                    closeModal();
                                }
                            });
                        })();

                    </script>

                    <?php else: ?>

                    <p class="member-profile-empty">
                        No achievements have been earned yet.
                    </p>

                    <?php endif; ?>

                </section>

                <?php endif; ?>

            </div>

        </div>

    </section>

</main>

<?php

require
    INCLUDES_PATH
    . '/footer.php';

?>
