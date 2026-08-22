"use client";

import React, { useEffect } from "react";
import { useEditor, EditorContent } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import Underline from "@tiptap/extension-underline";
import Link from "@tiptap/extension-link";

/**
 * ADR-028 addendum decision 16: one shared editor for every
 * admin-authored rich-text field in this codebase — today Footer
 * Settings' T&C/Privacy/About Us content, reused by any future field
 * needing the same editing surface rather than re-integrating Tiptap.
 *
 * Only the marks/nodes the backend's `rich_text` Purifier profile
 * (config/purifier.php) actually allows are enabled here — no
 * heading/blockquote/code block, since those would be silently
 * stripped on save anyway. Keeping the two in sync is a manual
 * discipline, not enforced by a shared source of truth; if the
 * allowlist ever changes, this toolbar needs updating too.
 */

interface RichTextEditorProps {
  value: string;
  onChange: (html: string) => void;
}

function ToolbarButton({
  onClick,
  active,
  title,
  className = "",
  children,
}: {
  onClick: () => void;
  active?: boolean;
  title: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      title={title}
      onClick={onClick}
      className={`flex h-7 w-7 items-center justify-center rounded-md text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.05] ${
        active ? "bg-brand-50 text-brand-600 dark:bg-brand-500/[0.15] dark:text-brand-400" : ""
      } ${className}`}
    >
      {children}
    </button>
  );
}

const RichTextEditor: React.FC<RichTextEditorProps> = ({ value, onChange }) => {
  const editor = useEditor({
    immediatelyRender: false,
    extensions: [
      StarterKit.configure({
        heading: false,
        blockquote: false,
        codeBlock: false,
        horizontalRule: false,
        code: false,
      }),
      Underline,
      Link.configure({ openOnClick: false, HTMLAttributes: { rel: "noopener noreferrer" } }),
    ],
    content: value,
    onUpdate: ({ editor }) => onChange(editor.getHTML()),
    editorProps: {
      attributes: {
        class:
          "min-h-[100px] px-3.5 py-3 text-sm text-gray-800 dark:text-white/90 focus:outline-hidden prose-sm max-w-none [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5",
      },
    },
  });

  // Syncs an external value change (e.g. loading a fresh record) into
  // the editor without fighting the user's own cursor while they type
  // — only pushed when the editor isn't the one that produced `value`.
  useEffect(() => {
    if (!editor) return;
    if (editor.isFocused) return;
    if (editor.getHTML() === value) return;
    editor.commands.setContent(value, { emitUpdate: false });
  }, [value, editor]);

  if (!editor) return null;

  function setLink() {
    if (!editor) return;
    const previousUrl = editor.getAttributes("link").href as string | undefined;
    const url = window.prompt("Link URL", previousUrl ?? "https://");
    if (url === null) return;
    if (url === "") {
      editor.chain().focus().extendMarkRange("link").unsetLink().run();
      return;
    }
    editor.chain().focus().extendMarkRange("link").setLink({ href: url }).run();
  }

  return (
    <div className="overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
      <div className="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50 px-2 py-1.5 dark:border-gray-800 dark:bg-white/[0.02]">
        <select
          className="mr-1 rounded-md border border-gray-300 bg-white px-2 py-1 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
          value="normal"
          onChange={() => undefined}
        >
          <option value="normal">Normal</option>
        </select>

        <ToolbarButton title="Bold" active={editor.isActive("bold")} onClick={() => editor.chain().focus().toggleBold().run()}>
          B
        </ToolbarButton>
        <ToolbarButton title="Italic" active={editor.isActive("italic")} onClick={() => editor.chain().focus().toggleItalic().run()}>
          <span className="italic">I</span>
        </ToolbarButton>
        <ToolbarButton title="Underline" active={editor.isActive("underline")} onClick={() => editor.chain().focus().toggleUnderline().run()}>
          <span className="underline">U</span>
        </ToolbarButton>
        <ToolbarButton title="Strikethrough" active={editor.isActive("strike")} onClick={() => editor.chain().focus().toggleStrike().run()}>
          <span className="line-through">S</span>
        </ToolbarButton>

        <span className="mx-1 h-4.5 w-px bg-gray-300 dark:bg-gray-700" />

        <ToolbarButton
          title="Ordered list"
          active={editor.isActive("orderedList")}
          onClick={() => editor.chain().focus().toggleOrderedList().run()}
        >
          1.
        </ToolbarButton>
        <ToolbarButton
          title="Bulleted list"
          active={editor.isActive("bulletList")}
          onClick={() => editor.chain().focus().toggleBulletList().run()}
        >
          •
        </ToolbarButton>

        <span className="mx-1 h-4.5 w-px bg-gray-300 dark:bg-gray-700" />

        <ToolbarButton title="Link" active={editor.isActive("link")} onClick={setLink}>
          <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6">
            <path d="M8 12l4-4M7.5 13a3 3 0 0 1 0-4.2l2-2a3 3 0 0 1 4.2 4.2l-.7.7M12.5 7a3 3 0 0 1 0 4.2l-2 2a3 3 0 0 1-4.2-4.2l.7-.7" />
          </svg>
        </ToolbarButton>
        <ToolbarButton title="Clear formatting" onClick={() => editor.chain().focus().unsetAllMarks().run()}>
          Tx
        </ToolbarButton>
      </div>
      <EditorContent editor={editor} />
    </div>
  );
};

export default RichTextEditor;
