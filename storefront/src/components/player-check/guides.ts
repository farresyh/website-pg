/**
 * ADR-123 decision 10: the Game ID Checker's per-family copy, keyed by the
 * validator key (stable), not the admin-editable URL slug. Written for
 * this site; redxgame.com/tools was only a reference for which topics a
 * checker page should cover.
 */

export interface FamilyGuide {
  /** Steps to find the ID inside the game. */
  findSteps: string[];
  /** Label for the ID field on this family. */
  idLabel: string;
  idPlaceholder: string;
  serverPlaceholder?: string;
}

const HOYOVERSE_NOTE = "Enter the UID only. The checker reads the server (Asia, America or Europe) from it.";

const GUIDES: Record<string, FamilyGuide> = {
  mlbb: {
    idLabel: "User ID",
    idPlaceholder: "e.g. 123456789",
    serverPlaceholder: "e.g. 2001",
    findSteps: [
      "Open Mobile Legends and tap your avatar in the top-left corner.",
      "Your profile shows a number followed by a second number in brackets, for example 123456789 (2001).",
      "The first number is your User ID. The number in brackets is your Server ID.",
    ],
  },
  freefire: {
    idLabel: "Player ID",
    idPlaceholder: "e.g. 1234567890",
    findSteps: [
      "Open Free Fire and tap your profile picture in the top-left of the lobby.",
      "Your Player ID is the number shown under your nickname.",
      "Tap the copy icon beside it to copy the ID without typing it.",
    ],
  },
  genshin: {
    idLabel: "UID",
    idPlaceholder: "e.g. 800000001",
    findSteps: [
      "Open Genshin Impact and enter the game world.",
      "Your UID is shown in the bottom-right corner of the screen. It is also on your profile in the Paimon menu.",
      HOYOVERSE_NOTE,
    ],
  },
  hsr: {
    idLabel: "UID",
    idPlaceholder: "e.g. 800000001",
    findSteps: [
      "Open Honkai: Star Rail and press the phone menu.",
      "Your UID is shown on your Trailblazer profile, beside your level.",
      HOYOVERSE_NOTE,
    ],
  },
  zzz: {
    idLabel: "UID",
    idPlaceholder: "e.g. 1300000001",
    findSteps: [
      "Open Zenless Zone Zero and open the menu.",
      "Your UID is shown on your Proxy profile card.",
      HOYOVERSE_NOTE,
    ],
  },
};

const DEFAULT_GUIDE: FamilyGuide = {
  idLabel: "Player ID",
  idPlaceholder: "e.g. 123456789",
  serverPlaceholder: "e.g. 1234",
  findSteps: ["Open the game and go to your profile.", "Your player ID is shown on the profile screen."],
};

export function guideFor(key: string): FamilyGuide {
  return GUIDES[key] ?? DEFAULT_GUIDE;
}

export interface FaqItem {
  question: string;
  answer: string;
}

/** Same questions on every family page, worded for that game. Also the FAQPage JSON-LD. */
export function faqFor(family: { key: string; name: string } | null, storeName: string): FaqItem[] {
  const familyName = family?.name ?? null;
  const game = familyName ?? "your game";
  return [
    {
      question: `Is the ${familyName ? `${familyName} ` : ""}ID checker free?`,
      answer: `Yes. Checking an ID on ${storeName} is free and needs no account or login.`,
    },
    {
      question: "What does the checker show?",
      answer: `The account's nickname and the ${storeName} store that tops up that account, so you know the ID is right and which store to buy from before you pay.`,
    },
    {
      question: "Why check my ID before topping up?",
      answer: `Credits are sent to exactly the ID you enter. A wrong digit or an account from another region can send the top-up to someone else or fail. A quick check confirms the nickname is yours first.`,
    },
    {
      question: "What if no account is found?",
      answer: `Check the ID again in ${game}${family?.key === "mlbb" ? ", including the Server ID in brackets" : ""}. Copy it from the profile screen instead of typing it if you can. If the nickname still doesn't appear, contact our support.`,
    },
    {
      question: "Do you need my password?",
      answer: "No. The checker only needs your player ID. We never ask for your password or login code, and nobody from our team will.",
    },
  ];
}
