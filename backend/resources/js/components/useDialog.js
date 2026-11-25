import { useEffect, useRef } from 'react';

/**
 * Shared behaviour for pop-up dialogs (AGENTS.md §15 accessibility):
 * - moves keyboard focus into the dialog when it opens,
 * - closes it when Escape is pressed,
 * - returns focus to whatever opened it when it closes.
 *
 * Usage: const dialogRef = useDialog(onClose);
 *        <div role="dialog" ref={dialogRef} tabIndex={-1} ...>
 */
export function useDialog(onClose) {
    const dialogRef = useRef(null);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        const opener = document.activeElement;
        dialogRef.current?.focus();

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                closeRef.current?.();
            }
        };
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
                opener.focus();
            }
        };
    }, []);

    return dialogRef;
}
